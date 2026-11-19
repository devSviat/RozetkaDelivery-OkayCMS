<?php

namespace Modules\Sviat\RozetkaDelivery\Quote;

use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryQuote;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\OrderPrice;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\PriceDecision;
use PHPUnit\Framework\TestCase;

class PriceDecisionTest extends TestCase
{
    private const array CORE = ['delivery_price' => 70.0, 'separate_delivery' => 0];

    public function testTariffGoesToTheOrder(): void
    {
        $decision = PriceDecision::forOrder(new DeliveryQuote(49.0, 'tariff'), self::CORE, coreDefault: 70.0);

        self::assertSame(['delivery_price' => 49.0, 'separate_delivery' => 0], $decision['info']);
        self::assertSame('tariff', $decision['source']);
    }

    /** Ціну, яку браузер прислав у формі, рішення не бачить: з ядра приходить лише те, що воно саме порахувало. */
    public function testPostedPriceIsIgnored(): void
    {
        $posted = ['delivery_price' => 0.0, 'separate_delivery' => 0];

        self::assertSame(49.0, PriceDecision::forOrder(new DeliveryQuote(49.0, 'tariff'), $posted)['info']['delivery_price']);
    }

    public function testReceiverAndFree(): void
    {
        $separate = ['delivery_price' => 70.0, 'separate_delivery' => 1];

        self::assertSame(['info' => ['delivery_price' => 0.0, 'separate_delivery' => 1], 'source' => 'receiver'], PriceDecision::forOrder(new DeliveryQuote(0.0, 'receiver', 49.0), $separate, coreDefault: 70.0));
        self::assertSame('free', PriceDecision::forOrder(new DeliveryQuote(0.0, 'free'), ['delivery_price' => 0, 'separate_delivery' => 0], coreDefault: 0.0)['source']);
    }

    public function testNoQuoteMeansTheCoreDecides(): void
    {
        self::assertSame(['info' => self::CORE, 'source' => null], PriceDecision::forOrder(null, self::CORE));
    }

    public function testExternalPromoWins(): void
    {
        $promo = ['delivery_price' => 0, 'separate_delivery' => 0];

        self::assertSame(['info' => $promo, 'source' => 'external'], PriceDecision::forOrder(new DeliveryQuote(49.0, 'tariff'), $promo, coreDefault: 70.0));
    }

    public function testOrderPriceComparesWithTheStoredMethod(): void
    {
        $delivery = (object)['price' => 70, 'free_from' => 1000, 'separate_payment' => 0];

        self::assertSame('tariff', OrderPrice::decide(new DeliveryQuote(49.0, 'tariff'), self::CORE, $delivery, (object)['total_price' => 500])['source']);
        self::assertSame('external', OrderPrice::decide(new DeliveryQuote(49.0, 'tariff'), ['delivery_price' => 10.0] + self::CORE, $delivery, (object)['total_price' => 500])['source']);
        self::assertSame(
            ['info' => ['delivery_price' => 0.0, 'separate_delivery' => 0], 'source' => 'free'],
            OrderPrice::decide(new DeliveryQuote(0.0, 'free'), ['delivery_price' => 0, 'separate_delivery' => 0], $delivery, (object)['total_price' => 1500]),
        );
    }
}
