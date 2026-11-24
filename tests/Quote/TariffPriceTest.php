<?php

namespace Modules\Sviat\RozetkaDelivery\Quote;

use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\TariffPrice;
use PHPUnit\Framework\TestCase;

class TariffPriceTest extends TestCase
{
    private static function config(array $settings = []): ShippingConfig
    {
        return ShippingConfig::fromSettings(static fn(string $key): mixed => $settings[$key] ?? null);
    }

    private static function delivery(array $override = []): object
    {
        return (object)($override + ['id' => 5, 'price' => 70, 'free_from' => 100000, 'separate_payment' => 0, 'paid' => 1]);
    }

    public function testSenderPaysShowsCartPrice(): void
    {
        $quote = TariffPrice::quote(self::delivery(), self::config(), 900.0);

        self::assertSame(49.0, $quote->price, 'the default tariff, not the price typed into the delivery method');
        self::assertSame('tariff', $quote->source);
        self::assertNull($quote->receiverPrice);
        self::assertSame(55.5, TariffPrice::quote(self::delivery(), self::config([ShippingConfig::CART_PRICE => '55.5']), 900.0)->price);
    }

    public function testFreeFrom(): void
    {
        $delivery = self::delivery(['free_from' => 1000]);

        self::assertSame('tariff', TariffPrice::quote($delivery, self::config(), 999.0)->source);
        $free = TariffPrice::quote($delivery, self::config(), 1000.0);
        self::assertSame(0.0, $free->price);
        self::assertSame('free', $free->source);
    }

    /** Тариф кошика 0: покупець бачить безкоштовну доставку, тож це обіцянка, як і поріг. */
    public function testZeroCartPriceIsFree(): void
    {
        $quote = TariffPrice::quote(self::delivery(), self::config([ShippingConfig::CART_PRICE => '0']), 900.0);

        self::assertSame(0.0, $quote->price);
        self::assertSame('free', $quote->source);
        self::assertSame('receiver', TariffPrice::quote(self::delivery(['separate_payment' => 1]), self::config([ShippingConfig::CART_PRICE => '0']), 900.0)->source, 'control: the receiver still pays apart');
    }

    public function testSeparatePaymentIsReceiverZero(): void
    {
        $quote = TariffPrice::quote(self::delivery(['separate_payment' => 1]), self::config(), 900.0);

        self::assertSame(0.0, $quote->price, 'the receiver pays the carrier, nothing goes into the order');
        self::assertSame('receiver', $quote->source);
        self::assertSame(49.0, $quote->receiverPrice, 'the cart shows "≈ 49 at pickup"');

        $silent = TariffPrice::quote(self::delivery(['separate_payment' => 1]), self::config([ShippingConfig::RECEIVER_PRICE_NOTE => '0']), 900.0);
        self::assertNull($silent->receiverPrice);
        self::assertSame('free', TariffPrice::quote(self::delivery(['separate_payment' => 1, 'free_from' => 500]), self::config(), 900.0)->source, 'over the threshold the shop pays');
    }
}
