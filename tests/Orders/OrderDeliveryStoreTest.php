<?php

namespace Modules\Sviat\RozetkaDelivery\Orders;

use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartSelection;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryView;
use PHPUnit\Framework\TestCase;

class OrderDeliveryStoreTest extends TestCase
{
    private const string CITY = 'cccccccc-0000-4000-8000-000000000001';
    private const string POINT = 'aaaaaaaa-0000-4000-8000-000000000014';

    private array $post = [];

    protected function setUp(): void
    {
        $this->post = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->post;
    }

    private static function city(): object
    {
        return (object)['id' => self::CITY, 'name_ua' => 'Тестове', 'type' => 'м.', 'district_ua' => 'Умовний', 'region_ua' => 'Вигадана'];
    }

    private static function point(): object
    {
        return (object)['id' => self::POINT, 'city_id' => self::CITY, 'number' => '20001', 'kind' => 'partner', 'title' => 'Партнер (тільки передоплата), вул. Умовна, 2', 'prepay_only' => 1];
    }

    public function testSnapshotFromDictionaryNotPost(): void
    {
        $_POST = ['point_title' => '<img src=x onerror=alert(1)>', 'city_name' => 'Підробка', 'rozetka_point_title' => ['6' => 'Підробка']];

        self::assertSame([
            'order_id' => 10,
            'city_id' => self::CITY,
            'city_name' => 'м. Тестове, Умовний, Вигадана',
            'point_id' => self::POINT,
            'point_title' => 'Партнер (тільки передоплата), вул. Умовна, 2',
            'point_number' => '20001',
            'point_kind' => 'partner',
            'prepay_only' => 1,
        ], OrderDeliveryStore::row(10, new CartSelection((object)['id' => 6], self::city(), self::point())));
    }

    public function testPricingKeepsOnlyRealDates(): void
    {
        self::assertSame(
            ['price' => 49.0, 'price_source' => 'tariff', 'cod' => 1, 'cod_fee' => 30.0, 'estimated_date' => '2026-11-21'],
            OrderDeliveryStore::pricing(49.0, 'tariff', true, 30.0, '2026-11-21'),
        );
        foreach (['<script>', '21.11.2026', '2026-02-31', '2026-11-21 14:00'] as $bad) {
            self::assertNull(OrderDeliveryStore::pricing(49.0, 'tariff', false, null, $bad)['estimated_date'], $bad);
        }
        self::assertNull(OrderDeliveryStore::pricing(49.0, 'tariff', false, 30.0, null)['cod_fee'], 'no fee without cash on delivery');
    }

    /** Довідник оновлюється щодня; замовлення показує те, що обрав покупець, а не нинішню назву точки. */
    public function testViewReadsSnapshotOnly(): void
    {
        $point = self::point();
        $row = (object)(OrderDeliveryStore::row(10, new CartSelection((object)['id' => 6], self::city(), $point)) + OrderDeliveryStore::pricing(0.0, 'receiver', true, 22.35, '2026-11-21') + ['estimated_date' => '2026-11-21 00:00:00']);
        $point->title = 'Нова назва';

        self::assertSame([
            'city' => 'м. Тестове, Умовний, Вигадана',
            'point' => 'Партнер (тільки передоплата), вул. Умовна, 2',
            'estimatedDate' => '2026-11-21',
            'receiverPays' => true,
            'cod' => true,
            'codFee' => 22.35,
        ], OrderDeliveryView::lines($row));
    }
}
