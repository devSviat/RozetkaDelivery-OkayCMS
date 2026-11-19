<?php

namespace Modules\Sviat\RozetkaDelivery\Backend;

require_once __DIR__ . '/../Support/SettingsWorld.php';
require_once __DIR__ . '/../Support/AdminHarness.php';
require_once __DIR__ . '/../Support/PartnerFixtures.php';
require_once __DIR__ . '/../Support/InMemoryDictionaryStore.php';

use Modules\Sviat\RozetkaDelivery\Support\AdminHarness;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryDictionaryStore;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryMutex;
use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\DictionaryStatusView;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\SenderPoints;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\SenderSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\ShippingSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PopularCities;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryCounts;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\StatusDictionary;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\GroupEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsStore;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use Modules\Sviat\RozetkaDelivery\Support\PartnerFixtures;
use Modules\Sviat\RozetkaDelivery\Support\SettingsPage;
use Modules\Sviat\RozetkaDelivery\Support\SettingsWorld;
use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Credentials;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\Controllers\RozetkaSettingsAdmin;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\KeySettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\KeySettingsView;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyChecker;
use PHPUnit\Framework\TestCase;

/** Сторінка налаштувань: справжній контролер, справжні класи доступу, фейковий транспорт і справжній Smarty. */
class SettingsPageRenderTest extends TestCase
{
    private const string SESSION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const string CITY = 'c1c1c1c1-0000-4000-8000-000000000001';
    private const string POINT = '0a0b0c0d-1111-4000-8000-000000000001';

    private SettingsWorld $world;
    private AdminHarness $page;

    protected function setUp(): void
    {
        // Форк звіряє токен із $_SESSION['id'], сток 4.5.3 — із session_id(): заповнюємо обидва.
        $_SESSION = ['id' => self::SESSION];
        session_id(self::SESSION);
        $this->world = new SettingsWorld();
        $this->world->values = [Credentials::TOKEN => 'tok-123', Credentials::TOKEN_ID => 7];
        $this->page = new AdminHarness();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        session_id('');
    }

    /** @return array<class-string, object> */
    protected function services(): array
    {
        return [
            KeySettings::class => $this->world->keySettings(),
            KeySettingsView::class => $this->world->keyView(),
            KeyChecker::class => $this->world->checker(),
            SenderSettings::class => new SenderSettings($this->world->settings, $this->senderPoints()),
            SenderPoints::class => $this->senderPoints(),
            ShippingSettings::class => new ShippingSettings($this->world->settings),
            CodSettings::class => new CodSettings($this->world->settings),
            DictionaryStatusView::class => new DictionaryStatusView($this->world->api->state, $this->counts(), new StatusDictionary($this->world->api->state), $this->world->api->clock()),
            DictionaryRunner::class => new DictionaryRunner($this->world->api->client(), new InMemoryDictionaryStore(), new StatusDictionary($this->world->api->state), $this->world->api->state, new InMemoryMutex(), $this->world->api->clock()),
            GroupsStore::class => $this->groupsStore(),
            PopularCities::class => new PopularCities($this->world->api->state, $this->createStub(EntityFactory::class)),
            DictionaryCounts::class => $this->counts(),
        ];
    }

    private function senderPoints(): SenderPoints
    {
        $points = $this->createStub(SenderPoints::class);
        $points->method('receivingCity')->willReturnCallback(static fn(string $id): ?string => $id === self::POINT ? self::CITY : null);
        $points->method('city')->willReturn(['id' => self::CITY, 'name' => '<i>Тестове</i>', 'type' => 'м.', 'region' => 'Умовна']);
        $points->method('forCity')->willReturn([['id' => self::POINT, 'number' => '10036', 'title' => '№10036, <b>вул. Вигадана</b>, 5']]);

        return $points;
    }

    private function counts(): DictionaryCounts
    {
        $counts = $this->createStub(DictionaryCounts::class);
        $counts->method('byKind')->willReturn(['pickup' => 3, 'store' => 1, 'postomat' => 1, 'partner' => 2, 'other' => 0]);
        $counts->method('cities')->willReturn(4);

        return $counts;
    }

    private function groupsStore(): GroupsStore
    {
        $entity = $this->createStub(GroupEntity::class);
        $entity->method('find')->willReturn([(object)['id' => 1, 'name' => '<s>Точки</s>', 'enabled' => 1, 'position' => 1, 'kinds' => '["pickup","store"]']]);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        return new GroupsStore($factory);
    }

    private function visit(string $method, array $post = []): void
    {
        $this->page->visit(RozetkaSettingsAdmin::class, $this->services(), $method, $post);
    }

    private function post(array $post): void
    {
        $this->visit('POST', $post + ['session_id' => self::SESSION]);
    }

    public function testPageNeverContainsSecrets(): void
    {
        $this->visit('GET');
        self::assertStringNotContainsString('tok-123', $this->page->content);

        $this->world->api->http->json(500, ['statusCode' => 500, 'message' => 'boom pw-123 tok-123']);
        $this->post(['rozetka_action' => 'issue_token', 'issue_phone' => '380501112233', 'issue_password' => 'pw-123']);

        self::assertStringContainsString('sviat__rozetka__issue_err_unreachable', json_encode($this->page->assigned));
        foreach (['tok-123', 'pw-123'] as $secret) {
            self::assertStringNotContainsString($secret, $this->page->content);
            self::assertStringNotContainsString($secret, json_encode($this->page->assigned));
        }
        self::assertStringContainsString('380501112233', $this->page->content, 'the phone comes back to the form');
    }

    public function testLongTokenShowsOnlyItsTail(): void
    {
        $this->world->values[Credentials::TOKEN] = 'tok-123-aaaa-bbbb-wxyz';
        $this->visit('GET');

        self::assertStringContainsString('••••wxyz', $this->page->content);
        self::assertStringNotContainsString('tok-123', $this->page->content);
    }

    public function testPasswordFieldAlwaysEmpty(): void
    {
        $this->world->api->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized']);
        $this->post(['rozetka_action' => 'issue_token', 'issue_phone' => '380501112233', 'issue_password' => 'pw-123']);

        self::assertMatchesRegularExpression('/<input[^>]+type="password"[^>]+name="issue_password"[^>]+value=""/', $this->page->content);
        self::assertMatchesRegularExpression('/<input[^>]+name="issue_password"[^>]+autocomplete="new-password"/', $this->page->content);
        self::assertMatchesRegularExpression('/<input[^>]+type="password"[^>]+name="token"[^>]+value=""/', $this->page->content);
    }

    public function testBannerShowsTokenFlag(): void
    {
        $this->visit('GET');
        self::assertStringNotContainsString('rozetka_auth_notice', $this->page->content);

        $this->world->api->flags->record(AuthFlags::TOKEN, RozetkaApiException::of(ApiErrorKind::BadToken, 'x'));
        $this->visit('GET');

        self::assertStringContainsString('rozetka_auth_notice', $this->page->content);
        self::assertStringContainsString(SettingsPage::lang('ua')['sviat__rozetka__auth_token'], $this->page->content);
    }

    public function testPartnerWarningsEscaped(): void
    {
        $this->world->api->http->json(200, PartnerFixtures::verify(['name' => '<script>alert(1)</script>', 'status' => '<b>blocked</b>', 'autoblock_days' => 2]));
        $this->post(['rozetka_action' => 'check_key']);

        self::assertStringNotContainsString('<script>alert(1)</script>', $this->page->content);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->page->content);
        self::assertStringNotContainsString('<b>blocked</b>', $this->page->content);
        self::assertStringContainsString(SettingsPage::lang('ua')['sviat__rozetka__warn_partner_status'], $this->page->content);
        self::assertStringContainsString(SettingsPage::lang('ua')['sviat__rozetka__warn_autoblock'], $this->page->content);
        self::assertStringNotContainsString(PartnerFixtures::EMAIL, $this->page->content);
        self::assertStringNotContainsString(PartnerFixtures::PHONE, $this->page->content);
    }

    public function testFormsArePostWithCsrf(): void
    {
        $this->visit('GET');

        preg_match_all('/<form\b[^>]*>(.*?)<\/form>/s', $this->page->content, $forms, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(3, count($forms));
        foreach ($forms as [$form, $body]) {
            self::assertMatchesRegularExpression('/method="post"/', $form);
            self::assertStringContainsString('name="session_id" value="' . self::SESSION . '"', $body);
            self::assertMatchesRegularExpression('/name="rozetka_action"/', $body);
        }
    }

    public function testPostWithoutSessionIs403AndChangesNothing(): void
    {
        $this->visit('POST', ['rozetka_action' => 'revoke_token']);

        self::assertSame(403, $this->page->status);
        self::assertSame([], $this->world->api->http->requests());
        self::assertSame('tok-123', $this->world->values[Credentials::TOKEN]);
    }

    public function testGetWithActionDoesNothing(): void
    {
        $_GET = ['rozetka_action' => 'revoke_token'];
        $this->visit('GET', ['rozetka_action' => 'revoke_token']);
        $_GET = [];

        self::assertSame([], $this->world->api->http->requests());
        self::assertSame('tok-123', $this->world->values[Credentials::TOKEN]);
    }

    public function testCheckShowsApiTextOnlyInResult(): void
    {
        $this->world->api->http->json(403, ['statusCode' => 403, 'message' => 'Договір призупинено']);
        $this->post(['rozetka_action' => 'check_key']);

        self::assertStringContainsString('Договір призупинено', $this->page->content);
        self::assertStringNotContainsString('Договір призупинено', json_encode($this->world->api->state->values, JSON_UNESCAPED_UNICODE));

        $this->visit('GET');
        self::assertStringNotContainsString('Договір призупинено', $this->page->content);
    }

    public function testRevokeHiddenWithoutTokenIdAndRevokeCheckboxNeedsId(): void
    {
        $this->visit('GET');
        self::assertStringContainsString('value="revoke_token"', $this->page->content);
        self::assertStringContainsString('name="revoke_previous"', $this->page->content);

        $this->world->values[Credentials::TOKEN_ID] = '';
        $this->visit('GET');
        self::assertStringNotContainsString('value="revoke_token"', $this->page->content);
        self::assertStringNotContainsString('name="revoke_previous"', $this->page->content);
    }

    public function testEveryLanguageRendersWithoutRawKeys(): void
    {
        $this->world->api->flags->record(AuthFlags::TOKEN, RozetkaApiException::of(ApiErrorKind::BadToken, 'x'));
        $this->world->api->http->json(200, PartnerFixtures::verify(['status' => 'blocked', 'autoblock_days' => 1, 'contract' => ['number' => 'RD-1', 'expiration_date' => '2020-01-01 00:00:00', 'sign_date' => '2019-01-01 00:00:00']]));
        $this->post(['rozetka_action' => 'check_key']);
        $vars = $this->page->assigned;

        foreach (['ua', 'en', 'ru'] as $language) {
            $html = SettingsPage::render($vars, $language);
            self::assertDoesNotMatchRegularExpression('/sviat__rozetka__\w+/', $html, $language);
        }
    }

    public function testSenderSavedAndBlocksEscaped(): void
    {
        $this->post(['rozetka_action' => 'sender', 'sender_last_name' => 'Петренко', 'sender_first_name' => 'Оксана', 'sender_phone' => '0501112233', 'sender_point_id' => self::POINT]);

        self::assertSame(self::POINT, $this->world->values[ShippingConfig::SENDER_POINT_ID]);
        self::assertSame(self::CITY, $this->world->values[ShippingConfig::SENDER_CITY_ID]);
        foreach (['<i>Тестове</i>', '<b>вул. Вигадана</b>', '<s>Точки</s>'] as $raw) {
            self::assertStringNotContainsString($raw, $this->page->content);
        }
        self::assertStringContainsString('&lt;b&gt;вул. Вигадана&lt;/b&gt;', $this->page->content);
    }

    public function testSenderErrorsKeepTypedValues(): void
    {
        $this->post(['rozetka_action' => 'sender', 'sender_last_name' => '', 'sender_first_name' => '"><script>x</script>', 'sender_phone' => '044', 'sender_point_id' => '']);

        self::assertArrayNotHasKey(ShippingConfig::SENDER_POINT_ID, $this->world->values);
        self::assertStringContainsString(SettingsPage::lang('ua')['sviat__rozetka__sender_err_point'], $this->page->content);
        self::assertStringNotContainsString('<script>x</script>', $this->page->content);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $this->page->content);
    }

    public function testCodFlagSavedOffByDefault(): void
    {
        $this->visit('GET');
        self::assertDoesNotMatchRegularExpression('/name="cod_enabled" value="1" checked/', $this->page->content);

        $this->post(['rozetka_action' => 'cod', 'cod_enabled' => '1', 'cod_fee_fixed' => '15', 'cod_fee_percent' => '1.5', 'cod_fiscal' => 'unknown']);
        self::assertSame(1, $this->world->values[CodConfig::ENABLED]);
        self::assertStringContainsString(SettingsPage::lang('ua')['sviat__rozetka__cod_fiscal_unknown_warning'], $this->page->content);
    }

    public function testDictionaryButtonOnlyQueues(): void
    {
        $this->post(['rozetka_action' => 'dictionaries_run']);

        self::assertSame([], $this->world->api->http->requests());
        self::assertNotNull($this->world->api->state->get('dictionary_request'));
        self::assertMatchesRegularExpression('/data-rz-busy="[^"]*" disabled/', $this->page->content);
    }

    public function testDictionarySignalsShown(): void
    {
        $this->world->api->state->set('prepay_mismatch', json_encode(['count' => 1, 'names' => ['<b>Партнер</b>']]));
        $this->world->api->state->set('unknown_types', '{"21":3}');
        $this->visit('GET');

        self::assertStringContainsString('&lt;b&gt;Партнер&lt;/b&gt;', $this->page->content);
        self::assertStringContainsString('21: 3', $this->page->content);
    }

    public function testShippingErrorsShownAndNothingSaved(): void
    {
        $this->post(['rozetka_action' => 'shipping', 'default_weight' => '20', 'box_length' => '30', 'box_width' => '20', 'box_height' => '15', 'cart_price' => '49', 'declare_total' => '1']);

        self::assertArrayNotHasKey(ShippingConfig::DEFAULT_WEIGHT, $this->world->values);
        self::assertStringContainsString(SettingsPage::lang('ua')['sviat__rozetka__shipping_err_weight'], $this->page->content);
        self::assertMatchesRegularExpression('/name="default_weight"[^>]+value="20"/', $this->page->content);
    }

    public function testPaymentCodBlockDisabledWithoutFlag(): void
    {
        $off = SettingsPage::render(['rozetka_codpay' => ['enabled' => false, 'on' => true, 'manual' => true]], 'ua', 'payment_cod.tpl');
        self::assertStringNotContainsString('sviat_rz_cod_form', $off, 'no marker: saving the payment leaves the column alone');
        self::assertStringContainsString(SettingsPage::lang('ua')['sviat__rozetka__codpay_disabled'], $off);

        $on = SettingsPage::render(['rozetka_codpay' => ['enabled' => true, 'on' => true, 'manual' => true]], 'ua', 'payment_cod.tpl');
        self::assertStringContainsString('name="sviat_rz_cod_form" value="1"', $on);
        self::assertMatchesRegularExpression('/name="sviat_rz_cod" value="1" type="checkbox" checked/', $on);
    }

    public function testDeliveryWarningsTranslated(): void
    {
        foreach (['ua', 'en', 'ru'] as $language) {
            $html = SettingsPage::render(['rozetka_delivery_warnings' => ['static_price', 'manual_without_cod']], $language, 'delivery_warning.tpl');
            self::assertDoesNotMatchRegularExpression('/sviat__rozetka__\w+/', $html, $language);
            self::assertStringContainsString('alert--error', $html);
        }
    }
}
