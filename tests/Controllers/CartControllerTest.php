<?php

namespace Modules\Sviat\RozetkaDelivery\Controllers;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/ArraySettings.php';
require_once __DIR__ . '/../Cart/PointFitTest.php';

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Modules\Sviat\RozetkaDelivery\Cart\PointFitTest;
use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Okay\Core\Cart;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\Select;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\DeliveriesEntity;
use Okay\Helpers\UserHelper;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\TransportException;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CitySearch;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PointList;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\UahTotal;
use Okay\Modules\Sviat\RozetkaDelivery\Controllers\CartController;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\GroupEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsStore;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\CartQuotes;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryDate;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\QuoteSession;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class CartControllerTest extends TestCase
{
    private const string CITY = 'cccccccc-0000-4000-8000-000000000001';
    private const string POINT = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string PARTNER = 'aaaaaaaa-0000-4000-8000-000000000014';
    private const string RECEIVE_ONLY = 'aaaaaaaa-0000-4000-8000-000000000003';
    private const string SENDER = 'aaaaaaaa-0000-4000-8000-0000000000ff';

    private ApiWorld $world;
    private EntityFactory $entities;
    private GroupsStore $groups;
    private QuoteSession $session;
    private array $server;

    /** @var list<object> */
    private array $rows;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_GET = [];
        $this->server = $_SERVER;
        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN']);
        $this->world = new ApiWorld();
        $this->session = new QuoteSession();
        $this->rows = [
            PointFitTest::point(['id' => self::POINT, 'city_id' => self::CITY, 'number' => '10001', 'title' => '№10001, вул. Вигадана, 1', 'address' => 'вул. Вигадана 1', 'schedule' => '[]', 'extra_schedule' => null]),
            PointFitTest::point(['id' => self::PARTNER, 'city_id' => self::CITY, 'number' => '20001', 'kind' => 'partner', 'type_id' => 14, 'prepay_only' => 1, 'title' => 'Партнер (тільки передоплата)', 'address' => 'вул. Умовна 2', 'schedule' => '[]', 'extra_schedule' => null]),
            PointFitTest::point(['id' => self::RECEIVE_ONLY, 'city_id' => self::CITY, 'number' => '30001', 'can_give_out' => 0, 'title' => '№30001', 'address' => '', 'schedule' => '[]', 'extra_schedule' => null]),
        ];
        $byId = array_column(array_map(static fn(object $r): array => ['id' => $r->id, 'row' => $r], $this->rows), 'row', 'id');
        $points = $this->createStub(PointEntity::class);
        $points->method('get')->willReturnCallback(static fn($id) => $byId[$id] ?? false);
        $groupEntity = $this->createStub(GroupEntity::class);
        $groupEntity->method('find')->willReturn([
            (object)['id' => 1, 'enabled' => 1, 'kinds' => '["pickup","store","postomat"]', 'name' => 'Точки'],
            (object)['id' => 3, 'enabled' => 1, 'kinds' => '["partner"]', 'name' => 'Партнерські'],
        ]);
        $deliveries = $this->createStub(DeliveriesEntity::class);
        $deliveries->method('get')->willReturnCallback(static fn($id) => match ((int)$id) {
            3 => (object)['id' => 3, 'module_id' => 5, 'enabled' => 1],
            4 => (object)['id' => 4, 'module_id' => 77, 'enabled' => 1],
            default => false,
        });
        $currencies = $this->createStub(CurrenciesEntity::class);
        $currencies->method('findOne')->willReturn(false);
        $this->entities = $this->createStub(EntityFactory::class);
        $this->entities->method('get')->willReturnCallback(static fn(string $class): object => match ($class) {
            PointEntity::class => $points,
            GroupEntity::class => $groupEntity,
            DeliveriesEntity::class => $deliveries,
            CurrenciesEntity::class => $currencies,
        });
        $this->groups = new GroupsStore($this->entities);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = [];
        $_SESSION = [];
    }

    private static function request(): Request
    {
        return new \ReflectionClass(Request::class)->newInstanceWithoutConstructor();
    }

    /** @return array{0: Response, 1: \ArrayObject} */
    private function response(): array
    {
        $seen = new \ArrayObject(['status' => 200, 'json' => null]);
        $response = $this->createStub(Response::class);
        $response->method('setStatusCode')->willReturnCallback(static function ($code) use ($seen, &$response) {
            $seen['status'] = $code;

            return $response;
        });
        $response->method('setContent')->willReturnCallback(static function ($content) use ($seen, &$response) {
            $seen['json'] = $content;

            return $response;
        });

        return [$response, $seen];
    }

    private static function cart(): Cart
    {
        return new class extends Cart {
            public function __construct()
            {
                $this->total_price = 500.0;
                $this->purchases = [(object)['variant_id' => 1, 'amount' => 1, 'variant' => (object)['id' => 1, 'weight' => 1.0]]];
            }

            public function get(): Cart
            {
                return $this;
            }
        };
    }

    private static function userHelper(): UserHelper
    {
        return new class extends UserHelper {
            public function __construct()
            {
            }

            public function mergeCart($onlyToLocal = false)
            {
            }
        };
    }

    /** База, що віддає задані рядки й записує запити: контролер не повинен іти ні в що, крім власних таблиць. */
    private static function database(array $rows): Database
    {
        return new class ($rows) extends Database {
            public array $statements = [];

            public function __construct(private array $rows)
            {
            }

            public function __destruct()
            {
            }

            public function query(\Aura\SqlQuery\QueryInterface $query, $debug = false)
            {
                $this->statements[] = $query->getStatement();

                return true;
            }

            public function results($field = null, $mapped = null)
            {
                return $this->rows;
            }
        };
    }

    private static function queryFactory(): QueryFactory
    {
        return new class (new AuraQueryFactory('mysql')) extends QueryFactory {
            public function newSelect()
            {
                $select = new \ReflectionClass(Select::class)->newInstanceWithoutConstructor();
                new \ReflectionProperty(Select::class, 'queryObject')->setValue($select, new AuraQueryFactory('mysql')->newSelect());

                return $select;
            }
        };
    }

    private function deliveryDate(): DeliveryDate
    {
        return new DeliveryDate($this->world->client(), $this->world->state, new ArraySettings([ShippingConfig::SENDER_POINT_ID => self::SENDER]), $this->session, $this->world->clock());
    }

    private function quotes(): CartQuotes
    {
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(5);

        return new CartQuotes(new ArraySettings(), $module, $this->createStub(Money::class), $this->entities);
    }

    private function date(array $get): \ArrayObject
    {
        $_GET = $get;
        [$response, $seen] = $this->response();
        new CartController()->date(self::request(), $response, $this->deliveryDate(), $this->entities, $this->groups, $this->session, $this->quotes());

        return $seen;
    }

    public function testCitiesJson(): void
    {
        $db = self::database([(object)['id' => self::CITY, 'name_ua' => 'Тестове', 'type' => 'м.', 'district_ua' => 'Умовний', 'region_ua' => 'Вигадана']]);
        $_GET = ['q' => 'тест'];
        [$response, $seen] = $this->response();

        new CartController()->cities(self::request(), $response, new CitySearch($db, self::queryFactory()), $this->session);

        self::assertSame(['cities' => [['id' => self::CITY, 'name' => 'Тестове', 'type' => 'м.', 'district' => 'Умовний', 'region' => 'Вигадана']]], json_decode($seen['json'], true));
        self::assertCount(1, $db->statements);
        self::assertStringContainsString('sviat__rozetka__cities', $db->statements[0]);
        self::assertSame([], $this->world->http->requests(), 'the city search never asks Rozetka');
    }

    public function testPointsCarryPrepayAndProblems(): void
    {
        $db = self::database($this->rows);
        $_GET = ['city_id' => strtoupper(self::CITY), 'group_id' => '3'];
        [$response, $seen] = $this->response();

        new CartController()->points(self::request(), $response, new PointList($db, self::queryFactory(), $this->groups, new ArraySettings()), self::cart(), self::userHelper(), new UahTotal($this->entities, $this->createStub(Money::class)), $this->session);

        self::assertSame(200, $seen['status']);
        $json = json_decode($seen['json'], true);
        self::assertSame([['id' => 1, 'name' => 'Точки', 'count' => 1], ['id' => 3, 'name' => 'Партнерські', 'count' => 1]], $json['groups'], 'the receive-only point is not counted');
        self::assertSame(self::PARTNER, $json['points'][0]['id']);
        self::assertTrue($json['points'][0]['prepay_only']);
        self::assertSame([], $json['points'][0]['problems']);
        self::assertSame(['prepay_only'], $json['points'][0]['cod_problems']);
        self::assertStringContainsString('`p`.`can_give_out` = 1', $db->statements[0]);
        self::assertSame([], $this->world->http->requests());

        $_GET = ['city_id' => '1 OR 1=1'];
        [$response, $seen] = $this->response();
        new CartController()->points(self::request(), $response, new PointList($db, self::queryFactory(), $this->groups, new ArraySettings()), self::cart(), self::userHelper(), new UahTotal($this->entities, $this->createStub(Money::class)), $this->session);
        self::assertSame(400, $seen['status']);
    }

    public function testDateForPoint(): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['delivery_date' => '2026-11-21 14:00:00', 'delivery_text' => null]]);

        $seen = $this->date(['point_id' => self::POINT, 'delivery_id' => '3']);

        self::assertSame(200, $seen['status']);
        self::assertSame('2026-11-21', json_decode($seen['json'], true)['date']);
        self::assertSame(self::POINT, $this->session->chosen(3), 'the choice is remembered for the cart page');
    }

    public function testCrossSiteRejected(): void
    {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

        $seen = $this->date(['point_id' => self::POINT, 'delivery_id' => '3']);

        self::assertSame(403, $seen['status']);
        self::assertSame([], $this->world->http->requests());
        self::assertSame([], $_SESSION, 'neither the choice nor the throttle is touched');

        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        $_SERVER['HTTP_ORIGIN'] = 'https://elsewhere.example';
        $_SERVER['HTTP_HOST'] = 'shop.example';
        self::assertSame(403, $this->date(['point_id' => self::POINT])['status']);
    }

    public function testDateNullOnApiError(): void
    {
        $this->world->http->push(new TransportException('timeout'));

        $seen = $this->date(['point_id' => self::POINT, 'delivery_id' => '3']);

        self::assertSame(200, $seen['status'], 'the cart goes on without a date');
        self::assertNull(json_decode($seen['json'], true)['date']);
    }

    public function testBadPointMakesNoCall(): void
    {
        foreach (['1; DROP', '', self::RECEIVE_ONLY, 'aaaaaaaa-0000-4000-8000-000000000009'] as $id) {
            self::assertSame(400, $this->date(['point_id' => $id, 'delivery_id' => '3'])['status'], $id);
        }
        self::assertSame([], $this->world->http->requests());
        self::assertSame([], $_SESSION);

        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['delivery_date' => '2026-11-21 14:00:00']]);
        $this->date(['point_id' => self::POINT, 'delivery_id' => '4']);
        self::assertNull($this->session->chosen(4), 'a delivery of another module is not remembered');
    }
}
