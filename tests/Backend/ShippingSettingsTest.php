<?php

namespace Modules\Sviat\RozetkaDelivery\Backend;

require_once __DIR__ . '/../Support/ArraySettings.php';

use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\ShippingSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class ShippingSettingsTest extends TestCase
{
    /** @return array<string, string> */
    private static function post(array $override = []): array
    {
        return $override + [
            'default_weight' => '1,5', 'box_length' => '40', 'box_width' => '30', 'box_height' => '20',
            'receiver_pay' => '1', 'declare_total' => '1', 'declared_value' => '', 'description' => 'Замовлення №{order}',
            'cutoff' => '15:30', 'cart_price' => '49', 'receiver_price_note' => '1',
        ];
    }

    public function testDeclaredValueOver30000Rejected(): void
    {
        $settings = new ArraySettings();
        $shipping = new ShippingSettings($settings);

        self::assertSame(['declared_value' => 'sviat__rozetka__shipping_err_declared_value'], $shipping->save(self::post(['declare_total' => '', 'declared_value' => '30000.01'])));
        self::assertSame(['declared_value' => 'sviat__rozetka__shipping_err_declared_value'], $shipping->save(self::post(['declare_total' => '', 'declared_value' => '0'])));
        self::assertSame([], $settings->values);
        self::assertSame([], $shipping->save(self::post(['declare_total' => '', 'declared_value' => '30000'])));
        self::assertSame(30000.0, $shipping->read()->declaredValue);
        self::assertFalse($shipping->read()->declareTotal);
    }

    public function testDefaultsWhenUnsaved(): void
    {
        $config = ShippingConfig::fromSettings(static fn(string $key): mixed => null);

        self::assertTrue($config->receiverPay);
        self::assertTrue($config->declareTotal);
        self::assertSame('Замовлення №{order}', $config->description);
        self::assertSame('16:00', $config->cutoff);
        self::assertSame(49.0, $config->cartPrice);
        self::assertTrue($config->receiverPriceNote);
        self::assertSame(1.0, $config->defaultWeightKg);
        self::assertSame(0.009, $config->volumeM3());
    }

    public function testSavedValuesRoundTrip(): void
    {
        $settings = new ArraySettings();
        $shipping = new ShippingSettings($settings);

        self::assertSame([], $shipping->save(self::post(['receiver_pay' => '', 'receiver_price_note' => '', 'cart_price' => '0'])));
        $config = $shipping->read();
        self::assertSame(1.5, $config->defaultWeightKg);
        self::assertSame(40, $config->lengthCm);
        self::assertFalse($config->receiverPay);
        self::assertFalse($config->receiverPriceNote);
        self::assertSame(0.0, $config->cartPrice);
        self::assertSame('15:30', $config->cutoff);
    }

    public function testLimitsFollowTheNetwork(): void
    {
        $errors = new ShippingSettings(new ArraySettings())->validate(self::post([
            'default_weight' => '15.5', 'box_length' => '121', 'description' => str_repeat('я', 251), 'cutoff' => '25:00', 'cart_price' => '-1',
        ]));

        self::assertSame([
            'default_weight' => 'sviat__rozetka__shipping_err_weight',
            'box_length' => 'sviat__rozetka__shipping_err_side',
            'description' => 'sviat__rozetka__shipping_err_description',
            'cutoff' => 'sviat__rozetka__shipping_err_cutoff',
            'cart_price' => 'sviat__rozetka__shipping_err_cart_price',
        ], $errors);
    }

    public function testCutoffChoices(): void
    {
        $choices = ShippingSettings::cutoffChoices('16:00');
        self::assertContains('16:00', $choices);
        self::assertSame('00:00', $choices[0]);
        self::assertCount(48, $choices);
        self::assertContains('16:45', ShippingSettings::cutoffChoices('16:45'));
    }
}
