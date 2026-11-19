<?php

namespace Modules\Sviat\RozetkaDelivery\Extenders;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/ArraySettings.php';
require_once __DIR__ . '/../Cart/PointFitTest.php';

use Modules\Sviat\RozetkaDelivery\Cart\PointFitTest;
use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Okay\Core\Cart;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\PaymentsEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\DictionaryReady;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PopularCities;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\UahTotal;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\GroupEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Extenders\FrontExtender;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsStore;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\CartQuotes;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryDate;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\QuoteSession;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;
use PHPUnit\Framework\TestCase;

/** Оформлення замовлення від перевірки форми до знімка: ціна, післяплата й комісія вирішуються на сервері. */
class FrontExtenderTest extends TestCase
{
    private const int MODULE = 9;
    private const int DELIVERY = 5;
    private const int CASH = 1;
    private const int CARD = 2;
    private const string CITY = 'cccccccc-0000-4000-8000-000000000001';
    private const string PICKUP = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string PARTNER = 'aaaaaaaa-0000-4000-8000-000000000014';

    /** @var list<array<string, mixed>> */
    private array $saved = [];
    private array $post = [];

    protected function setUp(): void
    {
        $this->post = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->post;
    }

    /** @var array<string, mixed> */
    private array $assigned = [];
    private object|false $snapshot = false;

    private function front(bool $codEnabled = true): FrontExtender
    {
        $settings = new ArraySettings([CodConfig::ENABLED => $codEnabled ? '1' : '0']);
        $rows = [
            DeliveriesEntity::class => [self::DELIVERY => self::delivery()],
            CityEntity::class => [self::CITY => (object)['id' => self::CITY, 'name_ua' => 'Тестове', 'type' => 'м.', 'district_ua' => null, 'region_ua' => 'Вигадана']],
            PointEntity::class => [
                self::PICKUP => PointFitTest::point(['id' => self::PICKUP, 'city_id' => self::CITY, 'number' => '10001', 'title' => '№10001, вул. Вигадана, 1']),
                self::PARTNER => PointFitTest::point(['id' => self::PARTNER, 'city_id' => self::CITY, 'kind' => 'partner', 'type_id' => 14, 'prepay_only' => 1, 'title' => 'Партнер (тільки передоплата)']),
            ],
            PaymentsEntity::class => [self::CASH => (object)['id' => self::CASH, 'module' => 'null', CodPayments::FIELD => 1], self::CARD => (object)['id' => self::CARD, 'module' => 'Sviat/Monobank', CodPayments::FIELD => 0]],
            GroupEntity::class => [(object)['id' => 1, 'enabled' => 1, 'kinds' => '["pickup","store","postomat"]', 'name' => 'Точки'], (object)['id' => 3, 'enabled' => 1, 'kinds' => '["partner"]', 'name' => 'Партнерські']],
        ];
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(function (string $class) use ($rows) {
            $entity = $this->createStub($class);
            if ($class === OrderDeliveryEntity::class) {
                $entity->method('findOne')->willReturnCallback(fn() => $this->snapshot);
                $entity->method('add')->willReturnCallback(function (array $row): int {
                    $this->saved[] = $row;

                    return count($this->saved);
                });

                return $entity;
            }
            $entity->method('get')->willReturnCallback(static fn($id) => $rows[$class][$id] ?? false);
            $entity->method('find')->willReturn(array_values($rows[$class] ?? []));
            $entity->method('cols')->willReturnSelf();
            $entity->method('findOne')->willReturn($class === CurrenciesEntity::class ? false : (object)['id' => self::CITY]);

            return $entity;
        });
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(self::MODULE);
        $translations = $this->createStub(FrontTranslations::class);
        $translations->method('getTranslation')->willReturnArgument(0);
        $state = $this->createStub(StateStorage::class);
        $money = $this->createStub(Money::class);
        // Money типово округлює до `cents` валюти; тут це 0, як буває в магазинах.
        $money->method('convert')->willReturnCallback(static fn($price, $currency = null, $format = true, $revers = false, $precision = null): string => number_format((float)$price, $precision ?? 0, '.', ''));
        $design = $this->createStub(Design::class);
        $design->method('assign')->willReturnCallback(function ($name, $value = null) use ($design) {
            $this->assigned[$name] = $value;

            return $design;
        });
        $codSettings = new CodSettings($settings);
        $codPayments = new CodPayments($factory, $codSettings);
        $groups = new GroupsStore($factory);
        $ready = new DictionaryReady($state, $factory);
        $store = new OrderDeliveryStore($factory);
        $world = new ApiWorld();

        return new FrontExtender(
            $design,
            $settings,
            $module,
            $factory,
            $translations,
            new CartSelectionValidator($factory, $module, $groups, $codPayments, $ready, $settings),
            $store,
            self::cart(),
            new CartQuotes($settings, $module, $money, $factory),
            $money,
            new UahTotal($factory, $money),
            new PopularCities($state, $factory),
            $ready,
            new OrderDeliveryLines($factory, $module, $store, $codPayments),
            $codPayments,
            $codSettings,
            // Без точки відправника дата не запитується: тест не йде в мережу.
            new DeliveryDate($world->client(), $world->state, $settings, QuoteSession::inMemory(), $world->clock()),
            QuoteSession::inMemory(),
        );
    }

    private static function delivery(): object
    {
        return (object)['id' => self::DELIVERY, 'module_id' => self::MODULE, 'price' => 70.0, 'free_from' => 100000, 'separate_payment' => 0];
    }

    private static function cart(): Cart
    {
        return new class extends Cart {
            public function __construct()
            {
                $this->total_price = 951.0;
                $this->purchases = [(object)['variant_id' => 1, 'amount' => 1, 'variant' => (object)['id' => 1, 'weight' => 1.0]]];
            }

            public function get(): Cart
            {
                return $this;
            }
        };
    }

    private static function order(int $payment, int $id = 0): object
    {
        return (object)['id' => $id ?: null, 'delivery_id' => self::DELIVERY, 'payment_method_id' => $payment, 'total_price' => 951.0];
    }

    private static function choose(string $pointId): void
    {
        $_POST = [
            CartSelectionValidator::CITY_FIELD => [self::DELIVERY => self::CITY],
            CartSelectionValidator::POINT_FIELD => [self::DELIVERY => $pointId],
            'delivery_price' => '0',
            'rozetka_price' => '0',
        ];
    }

    /** Повний шлях оформлення: перевірка форми, ціна доставки, знімок. */
    private function checkout(FrontExtender $front, int $payment): array
    {
        self::assertNull($front->getCartValidateError(null, self::order($payment)));
        $info = $front->orderPrice(['delivery_price' => 70.0, 'separate_delivery' => 0], self::delivery(), self::order($payment));
        $front->saveOrderDelivery(null, self::order($payment, 42));

        return $info;
    }

    public function testFormPriceIgnored(): void
    {
        self::choose(self::PICKUP);

        $info = $this->checkout($this->front(), self::CARD);

        self::assertSame(49.0, $info['delivery_price'], 'the tariff from the settings, not the posted 0 nor the method price');
        self::assertSame(49.0, $this->saved[0]['price']);
        self::assertSame('tariff', $this->saved[0]['price_source']);
    }

    public function testOrderGetsSnapshotWithCodFee(): void
    {
        self::choose(self::PICKUP);

        $this->checkout($this->front(), self::CASH);

        self::assertSame(1, $this->saved[0]['cod']);
        self::assertSame(30.0, $this->saved[0]['cod_fee'], '15 + 1.5 % of 951 + 49');
        self::assertSame(self::PICKUP, $this->saved[0]['point_id']);
        self::assertSame(42, $this->saved[0]['order_id']);
    }

    public function testCodFlagOffNoFee(): void
    {
        self::choose(self::PARTNER);

        $this->checkout($this->front(codEnabled: false), self::CASH);

        self::assertSame(0, $this->saved[0]['cod']);
        self::assertNull($this->saved[0]['cod_fee']);
        self::assertSame(self::PARTNER, $this->saved[0]['point_id'], 'with the flag off a manual payment is not cash on delivery');
    }

    public function testValidationErrorBlocksOrder(): void
    {
        self::choose(self::PARTNER);
        $front = $this->front();

        self::assertSame('sviat__rozetka__err_cod_prepay_only', $front->getCartValidateError(null, self::order(self::CASH)));
        self::assertSame('sviat__rozetka__err_cod_prepay_only', $front->getCartValidateError('', self::order(self::CASH)), 'an earlier link cleared its error as ""');
        self::assertSame('empty_phone', $front->getCartValidateError('empty_phone', self::order(self::CASH)));
        $info = $front->orderPrice(['delivery_price' => 70.0, 'separate_delivery' => 0], self::delivery(), self::order(self::CASH));
        self::assertSame(70.0, $info['delivery_price'], 'nothing validated, nothing priced');
    }

    public function testCartShowsCodFeeToTheKopeck(): void
    {
        $delivery = (object)(['payment_methods_ids' => [self::CASH, self::CARD], 'total_price_with_delivery' => 627.0] + (array)self::delivery());

        $view = $this->front()->codView([$delivery], (object)['total_price' => 578.0]);

        self::assertSame(['payments' => (string)self::CASH, 'fee' => '24.41 '], $view[self::DELIVERY], '15 + 1.5 % of 627');
    }

    /** Сторінка замовлення дає змінити оплату: ядро питає той самий хук, що й кошик. */
    public function testOrderPageRefusesCodForPrepayOnlyPoint(): void
    {
        $front = $this->front();
        $order = (object)['id' => 42, 'delivery_id' => self::DELIVERY, 'payment_method_id' => self::CARD];
        $this->snapshot = (object)['id' => 1, 'order_id' => 42, 'point_id' => self::PARTNER, 'prepay_only' => 1];

        self::assertFalse($front->paymentOffered(true, $order, self::CASH), 'cash on delivery to a prepay-only point');
        self::assertTrue($front->paymentOffered(true, $order, self::CARD));
        self::assertFalse($front->paymentOffered(false, $order, self::CARD), 'the core refusal stays');

        $this->snapshot = (object)['id' => 1, 'order_id' => 42, 'point_id' => self::PICKUP, 'prepay_only' => 0];
        self::assertTrue($front->paymentOffered(true, $order, self::CASH));
        $this->snapshot = (object)['id' => 1, 'order_id' => 42, 'point_id' => self::PARTNER, 'prepay_only' => 1];
        self::assertTrue($this->front(codEnabled: false)->paymentOffered(true, $order, self::CASH), 'with the flag off nothing is cash on delivery');
    }
}
