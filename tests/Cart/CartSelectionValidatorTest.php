<?php

namespace Modules\Sviat\RozetkaDelivery\Cart;

require_once __DIR__ . '/PointFitTest.php';
require_once __DIR__ . '/../Support/ArraySettings.php';

use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\PaymentsEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartMetrics;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartSelection;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\DictionaryReady;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\GroupEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsStore;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;
use PHPUnit\Framework\TestCase;

class CartSelectionValidatorTest extends TestCase
{
    private const int MODULE = 9;
    private const string CITY = 'cccccccc-0000-4000-8000-000000000001';
    private const string OTHER_CITY = 'cccccccc-0000-4000-8000-000000000002';
    private const string PICKUP = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string PARTNER = 'aaaaaaaa-0000-4000-8000-000000000014';
    private const string NAMED = 'aaaaaaaa-0000-4000-8000-000000000002';
    private const string RECEIVE_ONLY = 'aaaaaaaa-0000-4000-8000-000000000003';
    private const string ELSEWHERE = 'aaaaaaaa-0000-4000-8000-000000000004';
    private const int CASH = 1;
    private const int CARD = 2;

    private static function groupRows(): array
    {
        return [
            (object)['id' => 1, 'name' => 'Точки', 'enabled' => 1, 'position' => 1, 'kinds' => '["pickup","store","postomat"]'],
            (object)['id' => 3, 'name' => 'Партнерські', 'enabled' => 1, 'position' => 3, 'kinds' => '["partner"]'],
        ];
    }

    /** @return array<string, object> */
    private static function points(): array
    {
        return [
            self::PICKUP => PointFitTest::point(['id' => self::PICKUP, 'city_id' => self::CITY]),
            self::PARTNER => PointFitTest::point(['id' => self::PARTNER, 'city_id' => self::CITY, 'kind' => 'partner', 'type_id' => 14, 'prepay_only' => 1, 'title' => 'Партнер (тільки передоплата)']),
            self::NAMED => PointFitTest::point(['id' => self::NAMED, 'city_id' => self::CITY, 'kind' => 'pickup', 'type_id' => 2, 'prepay_only' => 1, 'title' => '№10050 (тільки передплата)']),
            self::RECEIVE_ONLY => PointFitTest::point(['id' => self::RECEIVE_ONLY, 'city_id' => self::CITY, 'can_give_out' => 0]),
            self::ELSEWHERE => PointFitTest::point(['id' => self::ELSEWHERE, 'city_id' => self::OTHER_CITY]),
        ];
    }

    private function validator(bool $codEnabled = true): CartSelectionValidator
    {
        $points = self::points();
        $byClass = [
            DeliveriesEntity::class => [5 => (object)['id' => 5, 'module_id' => self::MODULE], 6 => (object)['id' => 6, 'module_id' => 99]],
            CityEntity::class => [self::CITY => (object)['id' => self::CITY, 'name_ua' => 'Тестове'], self::OTHER_CITY => (object)['id' => self::OTHER_CITY, 'name_ua' => 'Інше']],
            PointEntity::class => $points,
            PaymentsEntity::class => [self::CASH => (object)['id' => self::CASH, 'module' => 'null', CodPayments::FIELD => 1], self::CARD => (object)['id' => self::CARD, 'module' => 'Sviat/Monobank', CodPayments::FIELD => 0]],
            GroupEntity::class => self::groupRows(),
        ];
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(function (string $class) use ($byClass) {
            $entity = $this->createStub($class);
            $entity->method('get')->willReturnCallback(static fn($id) => $byClass[$class][$id] ?? false);
            $entity->method('find')->willReturn(array_values($byClass[$class]));
            $entity->method('cols')->willReturnSelf();
            $entity->method('findOne')->willReturn((object)['id' => self::CITY]);

            return $entity;
        });
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(self::MODULE);
        $settings = new ArraySettings([CodConfig::ENABLED => $codEnabled ? '1' : '0']);

        return new CartSelectionValidator(
            $factory,
            $module,
            new GroupsStore($factory),
            new CodPayments($factory, new CodSettings($settings)),
            new DictionaryReady($this->createStub(StateStorage::class), $factory),
            $settings,
        );
    }

    private static function post(string $pointId, string $cityId = self::CITY, int $deliveryId = 5): array
    {
        return [CartSelectionValidator::CITY_FIELD => [$deliveryId => $cityId], CartSelectionValidator::POINT_FIELD => [$deliveryId => strtoupper($pointId)]];
    }

    private static function order(int $payment, int $deliveryId = 5): object
    {
        return (object)['delivery_id' => $deliveryId, 'payment_method_id' => $payment];
    }

    private static function cart(): CartMetrics
    {
        return new CartMetrics(1.0, false, 900.0, 900.0);
    }

    public function testCodToPrepayOnlyRejected(): void
    {
        self::assertSame('sviat__rozetka__err_cod_prepay_only', $this->validator()->validate(self::order(self::CASH), self::post(self::PARTNER), self::cart()));
    }

    public function testCodToNamedPrepayRejected(): void
    {
        self::assertSame('sviat__rozetka__err_cod_prepay_only', $this->validator()->validate(self::order(self::CASH), self::post(self::NAMED), self::cart()), 'type 2 with "передплата" in the name');
    }

    public function testPrepaidToPartnerAccepted(): void
    {
        $selection = $this->validator()->validate(self::order(self::CARD), self::post(self::PARTNER), self::cart());

        self::assertInstanceOf(CartSelection::class, $selection);
        self::assertSame(self::PARTNER, $selection->point?->id);
        self::assertFalse($selection->cod);
    }

    public function testCodFlagOffAcceptsPartner(): void
    {
        $selection = $this->validator(codEnabled: false)->validate(self::order(self::CASH), self::post(self::PARTNER), self::cart());

        self::assertInstanceOf(CartSelection::class, $selection, 'with the flag off no payment is cash on delivery');
        self::assertFalse($selection->cod);
        $cod = $this->validator()->validate(self::order(self::CASH), self::post(self::PICKUP), self::cart());
        self::assertInstanceOf(CartSelection::class, $cod);
        self::assertTrue($cod->cod);
    }

    public function testPointNotInCityRejected(): void
    {
        self::assertSame('sviat__rozetka__err_point', $this->validator()->validate(self::order(self::CARD), self::post(self::ELSEWHERE), self::cart()));
        self::assertSame('sviat__rozetka__err_city', $this->validator()->validate(self::order(self::CARD), self::post(self::PICKUP, ''), self::cart()));
        self::assertSame('sviat__rozetka__err_point', $this->validator()->validate(self::order(self::CARD), self::post(''), self::cart()));
        self::assertSame('sviat__rozetka__err_point_gone', $this->validator()->validate(self::order(self::CARD), self::post('aaaaaaaa-0000-4000-8000-0000000000ee'), self::cart()), 'a point that left the dictionary');
    }

    public function testReceiveOnlyPointRejected(): void
    {
        self::assertSame('sviat__rozetka__err_point', $this->validator()->validate(self::order(self::CARD), self::post(self::RECEIVE_ONLY), self::cart()));
    }

    public function testForeignDeliveryIsNotChecked(): void
    {
        $selection = $this->validator()->validate(self::order(self::CASH, 6), self::post(self::PARTNER, self::CITY, 6), self::cart());

        self::assertInstanceOf(CartSelection::class, $selection);
        self::assertFalse($selection->applies());
    }

    public function testStaticCheckReportsFit(): void
    {
        $points = self::points();
        $city = (object)['id' => self::CITY];
        $delivery = (object)['id' => 5, 'module_id' => self::MODULE];
        $check = static fn(bool $cod, CartMetrics $cart, bool $ready = true): CartSelection|string => CartSelectionValidator::check($delivery, self::MODULE, $city, $points[self::PICKUP], $cod, $cart, PointFitTest::box(), self::groupRows(), $ready);

        self::assertSame('sviat__rozetka__err_too_heavy', $check(false, new CartMetrics(16.0, false, 900.0, 900.0)));
        self::assertSame('sviat__rozetka__err_cod_over_limit', $check(true, new CartMetrics(1.0, false, 31000.0, 31000.0)));
        self::assertSame('sviat__rozetka__err_dictionary', $check(false, self::cart(), false));
    }
}
