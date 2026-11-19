<?php

namespace Modules\Sviat\RozetkaDelivery\Backend;

require_once __DIR__ . '/../Support/ArraySettings.php';

use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use PHPUnit\Framework\TestCase;

class CodSettingsTest extends TestCase
{
    public function testOffByDefault(): void
    {
        $config = new CodSettings(new ArraySettings())->read();

        self::assertFalse($config->enabled);
        self::assertSame(15.0, $config->feeFixed);
        self::assertSame(1.5, $config->feePercent);
        self::assertSame(CodConfig::FISCAL_UNKNOWN, $config->fiscal);
    }

    public function testFiscalChoices(): void
    {
        $settings = new ArraySettings();
        $cod = new CodSettings($settings);

        foreach (['shop', 'provider', 'unknown'] as $fiscal) {
            self::assertSame([], $cod->save(['cod_enabled' => '1', 'cod_fee_fixed' => '15', 'cod_fee_percent' => '1,5', 'cod_fiscal' => $fiscal]));
            self::assertSame($fiscal, $cod->read()->fiscal);
        }
        self::assertTrue($cod->read()->enabled);
        self::assertSame(['cod_fiscal' => 'sviat__rozetka__cod_err_fiscal'], $cod->save(['cod_fee_fixed' => '15', 'cod_fee_percent' => '1.5', 'cod_fiscal' => 'bank']));
        self::assertSame(['cod_fee_fixed' => 'sviat__rozetka__cod_err_fee', 'cod_fee_percent' => 'sviat__rozetka__cod_err_fee'], $cod->save(['cod_fee_fixed' => '-1', 'cod_fee_percent' => '101', 'cod_fiscal' => 'shop']));
        self::assertSame('unknown', CodConfig::fromSettings(static fn(string $k): mixed => $k === 'sviat__rozetka__cod_fiscal' ? 'bank' : null)->fiscal);

        $cod->save(['cod_fee_fixed' => '15', 'cod_fee_percent' => '1.5', 'cod_fiscal' => 'shop']);
        self::assertFalse($cod->read()->enabled, 'an unchecked switch turns it off');
    }

    public function testEstimateFee(): void
    {
        self::assertSame(30.0, new CodConfig(true, 15.0, 1.5)->estimateFee(1000.0));
        self::assertSame(15.03, new CodConfig(true, 15.0, 1.5)->estimateFee(2.0));
        self::assertSame(0.0, new CodConfig(true, 0.0, 0.0)->estimateFee(500.0));
    }
}
