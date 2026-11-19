<?php

namespace Modules\Sviat\RozetkaDelivery\Backend;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';
require_once __DIR__ . '/../Support/RecordingInit.php';
require_once __DIR__ . '/../Parcels/LabelsTest.php';

use Modules\Sviat\RozetkaDelivery\Parcels\LabelsTest;
use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryParcelRows;
use Modules\Sviat\RozetkaDelivery\Support\RecordingInit;
use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\BackendTranslations;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Modules;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\Controllers\ParcelAdmin;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\Labels;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDrafts;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelLookup;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelService;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelTracking;
use PHPUnit\Framework\TestCase;

/**
 * Rozetka тут — FakeTransport без підготовлених відповідей: запит, якого тест не чекає, кидає виняток.
 * Залежності, яких дія не повинна торкатися, створено без конструкторів: дійти до них означає впасти.
 */
class ParcelAdminTest extends TestCase
{
    private const string TRACK = '101123456789';
    private const array ACTIONS = ['create', 'lookup', 'choose', 'attach', 'delete', 'unlink', 'refresh', 'label'];

    /** @var array{status: ?int, body: mixed, type: ?string, headers: list<string>} */
    private array $answer;
    private ApiWorld $world;
    private InMemoryParcelRows $rows;
    private bool $active = true;
    /** null — типовий токен ApiWorld; '' — токен не задано. */
    private ?string $token = null;

    protected function setUp(): void
    {
        $_POST = [];
        $_SESSION = [];
        $this->world = new ApiWorld();
        $this->rows = new InMemoryParcelRows();
        $this->rows->created(7, self::TRACK);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        session_id('');
    }

    private function call(string $method, array $post): void
    {
        $this->answer = ['status' => null, 'body' => null, 'type' => null, 'headers' => []];
        $_POST = $post;
        $request = new class ($method) extends Request {
            public function __construct(private string $verb)
            {
            }

            public function method($method = null)
            {
                return $method === null ? $this->verb : strtolower((string)$method) === strtolower($this->verb);
            }
        };
        $answer = &$this->answer;
        $response = new class ($answer) extends Response {
            public function __construct(private array &$answer)
            {
            }

            public function setStatusCode($statusCode): Response
            {
                $this->answer['status'] = $statusCode;

                return $this;
            }

            public function addHeader($headerContent, $replace = true, $responseCode = null): Response
            {
                $this->answer['headers'][] = $headerContent;

                return $this;
            }

            public function setContent($content, $type = null): Response
            {
                $this->answer['type'] = $type;
                $this->answer['body'] = $type === RESPONSE_JSON ? json_decode((string)$content, true) : $content;

                return $this;
            }
        };

        $orders = $this->createStub(OrdersEntity::class);
        $orders->method('get')->willReturnCallback(static fn($id) => (int)$id === 7 ? (object)['id' => 7, 'delivery_id' => 6] : false);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($orders);

        $controller = new \ReflectionClass(ParcelAdmin::class)->newInstanceWithoutConstructor();
        $parent = new \ReflectionClass(IndexAdmin::class);
        $parent->getProperty('request')->setValue($controller, $request);
        $parent->getProperty('response')->setValue($controller, $response);
        $parent->getProperty('entityFactory')->setValue($controller, $entityFactory);

        $client = $this->world->client($this->token === null ? null : $this->world->credentials($this->token, null));
        $blank = static fn(string $class): object => new \ReflectionClass($class)->newInstanceWithoutConstructor();
        $translations = $blank(BackendTranslations::class);
        $lang = [];
        include dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/lang/ua.php';
        foreach ($lang as $key => $text) {
            $translations->addTranslation($key, $text);
        }
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(fn($vendor, $name): bool => $this->active && $vendor === 'Sviat' && $name === 'RozetkaDelivery');

        $controller->fetch(
            new ParcelService($client, $this->rows, new ParcelIssues(), $this->world->logger, $this->world->clock()),
            $blank(ParcelDrafts::class),
            $blank(ParcelLookup::class),
            $blank(ParcelTracking::class),
            new Labels($client, $this->world->logger, $this->world->clock()),
            $this->rows,
            $translations,
            $blank(OrderDeliveryLines::class),
            $modules,
        );
    }

    /** Форк звіряє токен із $_SESSION['id'], сток 4.5.3 — із session_id(): заповнюємо обидва. */
    private function signedIn(): string
    {
        $_SESSION['id'] = str_repeat('c', 64);
        session_id($_SESSION['id']);

        return $_SESSION['id'];
    }

    public function testGetRefused(): void
    {
        $before = $this->rows->rows;
        foreach (self::ACTIONS as $action) {
            $this->call('GET', ['action' => $action, 'order_id' => '7', 'track' => self::TRACK, 'session_id' => $this->signedIn()]);

            self::assertSame(405, $this->answer['status'], $action);
            self::assertSame(['ok' => false], $this->answer['body'], $action);
        }
        self::assertEquals($before, $this->rows->rows);
        self::assertSame([], $this->world->http->requests());
    }

    public function testCsrfChecked(): void
    {
        $before = $this->rows->rows;
        foreach (self::ACTIONS as $action) {
            $this->call('POST', ['action' => $action, 'order_id' => '7', 'track' => self::TRACK]);
            self::assertSame(403, $this->answer['status'], $action);

            $this->call('POST', ['action' => $action, 'order_id' => '7', 'session_id' => str_repeat('a', 64)]);
            self::assertSame(403, $this->answer['status'], "{$action}: wrong token");
        }
        self::assertEquals($before, $this->rows->rows);
        self::assertSame([], $this->world->http->requests());
    }

    public function testRightsOrders(): void
    {
        $calls = RecordingInit::record();

        self::assertContains(['controller', 'ParcelAdmin', null], $calls);
        self::assertContains(['permission', 'ParcelAdmin', 'orders'], $calls);
    }

    public function testDisabledModuleRefusesEveryAction(): void
    {
        $this->active = false;
        foreach (self::ACTIONS as $action) {
            $this->call('POST', ['action' => $action, 'order_id' => '7', 'track' => self::TRACK, 'session_id' => $this->signedIn()]);

            self::assertFalse($this->answer['body']['ok'], $action);
            self::assertSame((string)SettingsLang::get('sviat__rozetka__parcel_err_module_off'), $this->answer['body']['issues'][0]['text'], $action);
        }
        self::assertSame([], $this->world->http->requests());
    }

    public function testUnknownOrderAndActionRefused(): void
    {
        $this->call('POST', ['action' => 'label', 'order_id' => '8', 'session_id' => $this->signedIn()]);
        self::assertSame(404, $this->answer['status']);

        $this->call('POST', ['action' => 'erase', 'order_id' => '7', 'session_id' => $this->signedIn()]);
        self::assertSame(400, $this->answer['status']);
        self::assertSame([], $this->world->http->requests());
    }

    public function testLabelHeaders(): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['label' => base64_encode(LabelsTest::PDF)]]);

        $this->call('POST', ['action' => 'label', 'order_id' => '7', 'session_id' => $this->signedIn()]);

        self::assertSame(200, $this->answer['status']);
        self::assertSame(LabelsTest::PDF, $this->answer['body']);
        self::assertContains('Content-Type: application/pdf', $this->answer['headers']);
        self::assertContains('Content-Disposition: attachment; filename="rozetka-' . self::TRACK . '.pdf"', $this->answer['headers']);
        self::assertContains('X-Content-Type-Options: nosniff', $this->answer['headers']);
    }

    /** Без токена менеджер бачить, чого бракує, а не лише «Не вдалося»: текст і порада вже перекладені. */
    public function testLabelWithoutTokenExplainsWhy(): void
    {
        $this->token = '';

        $this->call('POST', ['action' => 'label', 'order_id' => '7', 'session_id' => $this->signedIn()]);

        self::assertSame(200, $this->answer['status']);
        self::assertFalse($this->answer['body']['ok']);
        self::assertSame((string)SettingsLang::get('sviat__rozetka__parcel_api_not_configured'), $this->answer['body']['issues'][0]['text']);
        self::assertSame((string)SettingsLang::get('sviat__rozetka__parcel_advice_key'), $this->answer['body']['issues'][0]['advice']);
        self::assertNotSame('', $this->answer['body']['issues'][0]['text']);
        self::assertSame([], $this->world->http->requests());
    }

    public function testNotPdfLabelIsJsonError(): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['label' => base64_encode('<svg onload="alert(1)"/>')]]);

        $this->call('POST', ['action' => 'label', 'order_id' => '7', 'session_id' => $this->signedIn()]);

        self::assertSame(RESPONSE_JSON, $this->answer['type']);
        self::assertFalse($this->answer['body']['ok']);
        self::assertStringNotContainsString('svg', json_encode($this->answer['body']));
        self::assertSame([], $this->answer['headers']);
    }
}

/** Тексти ua.php для порівняння з відповіддю. */
final class SettingsLang
{
    public static function get(string $key): ?string
    {
        $lang = [];
        include dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/lang/ua.php';

        return $lang[$key] ?? null;
    }
}
