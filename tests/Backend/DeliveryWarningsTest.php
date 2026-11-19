<?php

namespace Modules\Sviat\RozetkaDelivery\Backend;

use Okay\Modules\Sviat\RozetkaDelivery\Backend\DeliveryWarnings;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;
use PHPUnit\Framework\TestCase;

class DeliveryWarningsTest extends TestCase
{
    public function testManualPaymentWithoutCodWarns(): void
    {
        $delivery = (object)['id' => 5];
        $cash = (object)['id' => 1, 'module' => 'null', CodPayments::FIELD => 1];
        $transfer = (object)['id' => 3, 'module' => '', CodPayments::FIELD => 0];
        $online = (object)['id' => 2, 'module' => 'Sviat/Monobank', CodPayments::FIELD => 0];

        self::assertSame(['static_price', 'manual_without_cod'], DeliveryWarnings::forDelivery($delivery, [$cash], new CodConfig(false)));
        self::assertSame(['static_price'], DeliveryWarnings::forDelivery($delivery, [$cash, $online], new CodConfig(true)));
        self::assertSame(['static_price', 'manual_without_cod'], DeliveryWarnings::forDelivery($delivery, [$cash, $transfer], new CodConfig(true)));
        self::assertSame(['static_price'], DeliveryWarnings::forDelivery($delivery, [$online], new CodConfig(false)));
    }
}
