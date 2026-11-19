<?php

namespace Modules\Sviat\RozetkaDelivery\Orders;

require_once __DIR__ . '/../Cart/PointFitTest.php';

use Modules\Sviat\RozetkaDelivery\Cart\PointFitTest;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartMetrics;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderPointChange;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderPriceRule;
use PHPUnit\Framework\TestCase;

class OrderPointChangeTest extends TestCase
{
    private const int MODULE = 81;
    private const string CITY = 'cccccccc-0000-4000-8000-000000000001';

    private static function delivery(): object
    {
        return (object)['id' => 6, 'module_id' => self::MODULE, 'free_from' => 1_000_000, 'separate_payment' => 0, 'price' => 70];
    }

    private static function groupRows(): array
    {
        return [
            (object)['id' => 1, 'name' => 'Точки', 'enabled' => 1, 'position' => 1, 'kinds' => '["pickup","store","postomat"]'],
            (object)['id' => 3, 'name' => 'Партнерські', 'enabled' => 1, 'position' => 3, 'kinds' => '["partner"]'],
        ];
    }

    private static function city(string $id = self::CITY): object
    {
        return (object)['id' => $id, 'name_ua' => 'Тестове', 'type' => 'м.', 'district_ua' => null, 'region_ua' => 'Вигадана'];
    }

    private static function select(object $point, bool $cod = false, float $weight = 1.0, ?object $city = null, ?object $delivery = null, ?object $parcel = null): array
    {
        return OrderPointChange::select($delivery ?? self::delivery(), self::MODULE, $city ?? self::city(), $point, self::groupRows(), new CartMetrics($weight, false, 900.0, 900.0), PointFitTest::box(), $cod, $parcel);
    }

    /** ЕН іде в точку, з якою її створено: інша точка в замовленні розійшлася б із накладною. */
    public function testParcelLocksPointChange(): void
    {
        $point = PointFitTest::point(['city_id' => self::CITY]);
        foreach (['created' => 'op_err_locked', 'pending' => 'op_err_locked_check', 'uncertain' => 'op_err_locked_check'] as $state => $error) {
            $locked = self::select($point, parcel: (object)['state' => $state, 'track_id' => $state === 'created' ? '101123456789' : null]);
            self::assertSame($error, $locked['error'], $state);
            self::assertNull($locked['selection'], $state);
        }
        self::assertTrue(OrderPointChange::locks((object)['state' => 'created']));
        self::assertFalse(OrderPointChange::locks(null), 'a deleted parcel leaves no row');
        self::assertNotNull(self::select($point)['selection'], 'control: no parcel');
    }

    public function testCodOrderToPrepayOnlyRefused(): void
    {
        $partner = PointFitTest::point(['city_id' => self::CITY, 'kind' => 'partner', 'type_id' => 14, 'prepay_only' => 1]);

        $refused = self::select($partner, cod: true);
        self::assertSame('op_err_cod_prepay_only', $refused['error']);
        self::assertNull($refused['selection']);

        $prepaid = self::select($partner);
        self::assertNull($prepaid['error'], 'control: a prepaid order goes to the partner point');
        self::assertFalse($prepaid['selection']->cod);
    }

    public function testParcelSizeIsWarningOnly(): void
    {
        $result = self::select(PointFitTest::point(['city_id' => self::CITY]), weight: 16.0);

        self::assertNull($result['error']);
        self::assertSame('op_warn_too_heavy', $result['warning'], 'the manager knows the real parcel');
        self::assertNotNull($result['selection']);
    }

    public function testWrongPointRefused(): void
    {
        self::assertSame('op_err_point', self::select(PointFitTest::point(['city_id' => 'cccccccc-0000-4000-8000-000000000002']))['error']);
        self::assertSame('op_err_point', self::select(PointFitTest::point(['city_id' => self::CITY, 'can_give_out' => 0]))['error']);
        self::assertSame('order_point_foreign', self::select(PointFitTest::point(['city_id' => self::CITY]), delivery: (object)['id' => 9, 'module_id' => 12])['error']);
    }

    public function testPriceRecalculated(): void
    {
        $unpaid = (object)['total_price' => 970.0, 'delivery_price' => 70.0, 'separate_delivery' => 0, 'paid' => 0];

        self::assertSame(['action' => 'offer', 'price' => 49.0], OrderPriceRule::decide($unpaid, self::delivery(), 49.0), 'price in the order total is only offered');
        self::assertSame(['action' => 'keep', 'price' => 49.0], OrderPriceRule::decide((object)(['delivery_price' => 49.0, 'total_price' => 949.0] + (array)$unpaid), self::delivery(), 49.0));
        self::assertSame(['action' => 'show', 'price' => 49.0], OrderPriceRule::decide((object)(['paid' => 1] + (array)$unpaid), self::delivery(), 49.0), 'a paid order is never repriced');

        // Тариф від точки не залежить: зміна точки ніколи сама не переписує ціну, яку задав менеджер.
        $separate = OrderPriceRule::decide((object)['total_price' => 900.0, 'delivery_price' => 70.0, 'separate_delivery' => 1, 'paid' => 0], self::delivery(), 0.0);
        self::assertSame(['action' => 'offer', 'price' => 0.0], $separate, 'only offered, never written by the point change');
        self::assertStringNotContainsString('writePrice', self::method('save'), 'save() leaves the order price alone');
        self::assertSame(['action' => 'free', 'price' => 0.0], OrderPriceRule::decide($unpaid, (object)(['free_from' => 500] + (array)self::delivery()), 49.0));
    }

    private static function method(string $name): string
    {
        $source = (string)file_get_contents((string)new \ReflectionClass(OrderPointChange::class)->getFileName());
        $start = (int)strpos($source, 'function ' . $name . '(');

        return substr($source, $start, (int)strpos($source, "\n    }\n", $start) - $start);
    }
}
