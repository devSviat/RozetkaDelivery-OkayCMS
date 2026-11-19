<?php

namespace Modules\Sviat\RozetkaDelivery\Quote;

use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryPriceView;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryQuote;
use PHPUnit\Framework\TestCase;

class DeliveryPriceViewTest extends TestCase
{
    private const array TEXTS = ['free' => 'Безкоштовно', 'separate' => 'оплачується окремо', 'receiver' => '≈ %s за тарифом, при отриманні', 'receiver_plain' => 'оплата при отриманні'];

    private static function apply(DeliveryQuote $quote, array $delivery = []): object
    {
        $item = (object)($delivery + ['price' => 70.0, 'separate_payment' => 0, 'is_free_delivery' => false, 'delivery_price_text' => '70 грн', 'total_price_with_delivery' => 570.0]);
        DeliveryPriceView::apply($item, $quote, 500.0, static fn(float $v): string => number_format($v, 0, '.', ' '), 'грн', self::TEXTS);

        return $item;
    }

    public function testTariff(): void
    {
        $item = self::apply(new DeliveryQuote(49.0, 'tariff'));

        self::assertSame([49.0, '49 грн', 549.0, false], [$item->price, $item->delivery_price_text, $item->total_price_with_delivery, $item->is_free_delivery]);
    }

    public function testReceiverAddsNothing(): void
    {
        $item = self::apply(new DeliveryQuote(0.0, 'receiver', 49.0), ['separate_payment' => 1]);

        self::assertSame([0.0, '≈ 49 грн за тарифом, при отриманні', 500.0], [$item->price, $item->delivery_price_text, $item->total_price_with_delivery]);
        self::assertSame('оплата при отриманні', self::apply(new DeliveryQuote(0.0, 'receiver'), ['separate_payment' => 1])->delivery_price_text);
    }

    public function testFreeAndAlreadyFree(): void
    {
        $item = self::apply(new DeliveryQuote(0.0, 'free'));
        self::assertSame([0.0, 'Безкоштовно', 500.0, true], [$item->price, $item->delivery_price_text, $item->total_price_with_delivery, $item->is_free_delivery]);

        $promo = self::apply(new DeliveryQuote(49.0, 'tariff'), ['is_free_delivery' => true, 'price' => 0.0, 'delivery_price_text' => 'Акція']);
        self::assertSame('Акція', $promo->delivery_price_text, 'a promotion earlier in the chain wins');
    }
}
