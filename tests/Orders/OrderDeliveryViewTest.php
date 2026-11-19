<?php

namespace Modules\Sviat\RozetkaDelivery\Orders;

use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryView;
use PHPUnit\Framework\TestCase;

class OrderDeliveryViewTest extends TestCase
{
    private static function snapshot(int $cod, ?float $fee): object
    {
        return (object)['city_name' => 'м. Тестове', 'point_title' => 'Точка видачі №1', 'estimated_date' => null, 'price_source' => 'tariff', 'cod' => $cod, 'cod_fee' => $fee];
    }

    /** Покупець міг змінити оплату на сторінці замовлення: післяплату показує поточний спосіб, а не знімок кошика. */
    public function testCodFromCurrentPayment(): void
    {
        $switchedToCard = OrderDeliveryView::lines(self::snapshot(1, 30.0), false);
        self::assertFalse($switchedToCard['cod']);
        self::assertNull($switchedToCard['codFee']);

        $switchedToCod = OrderDeliveryView::lines(self::snapshot(0, null), true);
        self::assertTrue($switchedToCod['cod']);
        self::assertNull($switchedToCod['codFee'], 'the cart never estimated a fee for this order');

        self::assertSame(30.0, OrderDeliveryView::lines(self::snapshot(1, 30.0), true)['codFee']);
    }
}
