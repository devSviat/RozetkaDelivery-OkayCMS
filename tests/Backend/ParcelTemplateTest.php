<?php

namespace Modules\Sviat\RozetkaDelivery\Backend;

require_once __DIR__ . '/../Support/SettingsPage.php';
require_once __DIR__ . '/../Support/DraftFixtures.php';
require_once __DIR__ . '/../Parcels/ParcelCardTest.php';

use Modules\Sviat\RozetkaDelivery\Parcels\ParcelCardTest;
use Modules\Sviat\RozetkaDelivery\Support\DraftFixtures as F;
use Modules\Sviat\RozetkaDelivery\Support\SettingsPage;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDraft;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelView;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use PHPUnit\Framework\TestCase;

/** Адмінка без автоекранування: кожне значення з API чи замовлення мусить прийти в HTML екранованим. */
class ParcelTemplateTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private static function render(?object $row, ?ParcelDraft $draft = null, string $fiscal = CodConfig::FISCAL_UNKNOWN): string
    {
        $view = ParcelView::build(77, $row, true, static fn(): ParcelDraft|string => $draft ?? 'sviat__rozetka__parcel_err_no_point', [], self::NOW, $fiscal);

        return SettingsPage::render(['rozetka_parcel' => $view], 'ua', 'parcel.tpl');
    }

    public function testEverythingEscaped(): void
    {
        $row = ParcelCardTest::row([
            'event_name' => '<img src=x onerror=alert(1)>',
            'details' => json_encode(['recipient_name' => 'Тестова "Олена" <b>', 'point_title' => "Точка 'одна'"], JSON_UNESCAPED_UNICODE),
            'history' => json_encode([['code' => null, 'raw' => 'x', 'name' => '<script>alert(2)</script>', 'at' => '2027-01-15 10:00:00']]),
        ]);

        $html = self::render($row);

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<script>alert(2)', $html);
        self::assertStringContainsString('Тестова &quot;Олена&quot; &lt;b&gt;', $html);
        self::assertStringContainsString('101123456789', $html);
    }

    public function testFormFieldsEscaped(): void
    {
        $draft = F::draft(['order' => F::order(['paid' => 1, 'name' => '"><svg onload=alert(1)>', 'last_name' => 'Тестова'])]);

        $html = self::render(null, $draft);

        self::assertStringNotContainsString('<svg onload', $html);
        self::assertStringContainsString('&quot;&gt;&lt;svg', $html);
    }

    public function testReleaseUnpaidOnlyWhenAwaiting(): void
    {
        $awaiting = self::render(null, F::draft());
        self::assertMatchesRegularExpression('/<label class="rz-parcel__check fn_rz_parcel_release">/', $awaiting);

        $paid = self::render(null, F::draft(['order' => F::order(['paid' => 1])]));
        self::assertStringNotContainsString('data-field="release_unpaid"', $paid);

        // Післяплата є, але менеджер може прибрати суму: позначка лежить схованою, її показує скрипт.
        $cod = self::render(null, F::draft(['cod' => true]));
        self::assertMatchesRegularExpression('/<label class="rz-parcel__check fn_rz_parcel_release" hidden>/', $cod);
        self::assertStringContainsString('data-field="cod_amount"', $cod);
    }

    public function testUnknownFiscalBannerOnCodForm(): void
    {
        $banner = (string)SettingsPage::lang('ua')['sviat__rozetka__cod_fiscal_unknown_banner'];

        self::assertStringContainsString(htmlspecialchars($banner, ENT_QUOTES), self::render(null, F::draft(['cod' => true])));
        self::assertStringNotContainsString(htmlspecialchars($banner, ENT_QUOTES), self::render(null, F::draft(['cod' => true]), CodConfig::FISCAL_SHOP));
    }

    public function testCardActionsFollowGroup(): void
    {
        self::assertStringContainsString('fn_rz_parcel_delete', self::render(ParcelCardTest::row(['status_group' => 'created'])));
        self::assertStringNotContainsString('fn_rz_parcel_delete', self::render(ParcelCardTest::row(['status_group' => 'in_transit'])));
    }

    private const string LOGO = '/Okay/Modules/Sviat/RozetkaDelivery/Backend/design/images/rozetka.svg';

    /** Знак перевізника з файла модуля, не з сайту Rozetka: адмінка не тягне чужих ресурсів. */
    public function testCardShowsBundledLogo(): void
    {
        $view = ParcelView::build(77, ParcelCardTest::row(), true, static fn(): string => 'sviat__rozetka__parcel_err_no_point', [], self::NOW);
        $html = SettingsPage::render(['rozetka_parcel' => $view, 'rootUrl' => 'https://shop.example'], 'ua', 'parcel.tpl');

        self::assertStringContainsString('<img class="rz-logo rz-logo--card" src="https://shop.example' . self::LOGO . '" width="19" height="19" alt="ROZETKA Delivery">', $html);
        self::assertStringNotContainsString('rz-mark', $html);
        self::assertStringNotContainsString('rozetka.com.ua', $html);
        self::assertStringNotContainsString('.rz-mark', (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/design/css/parcel.css'));
    }

    public function testListRowShowsSmallLogo(): void
    {
        $order = (object)['rozetka_list_parcel' => ['track' => '101123456789', 'group' => 'in_transit', 'statusKey' => 'sviat__rozetka__group_in_transit', 'eventName' => null]];
        $html = SettingsPage::render(['order' => $order, 'rootUrl' => 'https://shop.example'], 'ua', 'orders_status.tpl');

        self::assertStringContainsString('<img class="rz-logo" src="https://shop.example' . self::LOGO . '" width="14" height="14" alt="ROZETKA Delivery">', $html);
        self::assertStringNotContainsString('rz-mark', $html);
    }

    /** Помилка в картці не лягає впритул на лінію футера; у формі — на кнопки. */
    public function testIssueAlertKeepsGapAboveFooter(): void
    {
        $css = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/design/css/parcel.css');

        $card = '.rz-card__issues .alert:last-child { margin-bottom: 12px; }';
        $any = '.rz-parcel__issues .alert:last-child { margin-bottom: 8px; }';
        self::assertStringContainsString($card, $css);
        self::assertStringContainsString($any, $css);
        // Однакова специфічність: правило картки мусить іти після загального, інакше картка отримає 8px.
        self::assertGreaterThan(strpos($css, $any), strpos($css, $card));
        self::assertStringNotContainsString('.rz-mark', $css);
    }

    /** Від заголовка до картки — 15px, як у блоку ядра «Параметри замовлення» (нижній відступ .heading_box). */
    public function testCardHeadingGapMatchesCore(): void
    {
        $css = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/design/css/parcel.css');

        self::assertStringContainsString('.rz-parcel--card .rz-parcel__head { margin-bottom: 15px; }', $css);
    }

    /** Знак у картці заввишки з цифру номера (19px) на його базовій лінії, 8px до номера; viewBox файла обрізано по фарбі. */
    public function testCardLogoMatchesDigitHeight(): void
    {
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/design';

        self::assertStringStartsWith('<svg viewBox="0 0.74 42.16 42.26" ', (string)file_get_contents($root . '/images/rozetka.svg'));
        self::assertStringContainsString('.rz-logo--card { height: 19px; align-self: baseline; margin-right: -4px; }', (string)file_get_contents($root . '/css/parcel.css'));
        self::assertStringContainsString('.rz-card__head .rz-card__number { align-self: baseline; padding-left: 0; }', (string)file_get_contents($root . '/css/parcel.css'));
        self::assertStringContainsString('.rz-logo { flex: none; display: block; width: auto;', (string)file_get_contents($root . '/css/parcel.css'));
    }

    /** Знак у рядку списку замовлень: 14px фарби, як у інших перевізників; проміжок до номера — gap 5px рядка. */
    public function testListLogoInkIs14pxTall(): void
    {
        $css = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/design/css/parcel.css');

        self::assertStringContainsString('.rz-logo { flex: none; display: block; width: auto; height: 14px; }', $css);
        self::assertStringContainsString('.rz-list-status { margin-top: 4px; display: flex; flex-wrap: wrap; align-items: center; gap: 5px;', $css);
    }

    /** Номер копіюється так само, як у Нової Пошти: елемент ядра .fn_clipboard, підказка «Натисніть, щоб скопіювати», жовте «Скопійовано». */
    public function testNumberCopiesLikeNovaPoshta(): void
    {
        $card = self::render(ParcelCardTest::row());
        $list = SettingsPage::render(['order' => (object)['rozetka_list_parcel' => ['track' => '101123456789', 'group' => 'in_transit', 'statusKey' => 'sviat__rozetka__group_in_transit', 'eventName' => null]]], 'ua', 'orders_status.tpl');
        $lang = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/lang/ua.php');

        self::assertMatchesRegularExpression('~<a href="" class="rz-card__number fn_clipboard fn_rz_copy hint-bottom-middle-t-info-s-small-mobile hint-anim" data-hint="Натисніть, щоб скопіювати" data-hint-copied="Скопійовано" data-copy-string="101123456789"~u', $card);
        self::assertMatchesRegularExpression('~<a href="" class="rz-list-status__track fn_clipboard fn_rz_copy hint-bottom-middle-t-info-s-small-mobile hint-anim" data-hint="Натисніть, щоб скопіювати" data-hint-copied="Скопійовано" data-copy-string="101123456789"~u', $list);
        self::assertStringNotContainsString('sviat__rozetka__list_copy', $lang);
    }

    /** Значок посилання й бейдж статусу центровані на цифрах номера, а не на його рядку: у Inter 26px/1.2 центр цифр на 1.35px вище. */
    public function testRowItemsCentredOnDigits(): void
    {
        $css = (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/design/css/parcel.css');

        self::assertStringContainsString('.rz-card__head .rz-parcel__badge, .rz-card__head .rz-card__track { transform: translateY(-1.35px); }', $css);
    }
}
