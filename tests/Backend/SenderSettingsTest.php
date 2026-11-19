<?php

namespace Modules\Sviat\RozetkaDelivery\Backend;

require_once __DIR__ . '/../Support/ArraySettings.php';

use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\SenderPoints;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\SenderSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class SenderSettingsTest extends TestCase
{
    public const string CITY = 'c1c1c1c1-0000-4000-8000-000000000001';
    public const string RECEIVING = '0a0b0c0d-1111-4000-8000-000000000001';
    public const string GIVE_OUT_ONLY = '0a0b0c0d-1111-4000-8000-000000000002';

    private ArraySettings $settings;

    protected function setUp(): void
    {
        $this->settings = new ArraySettings();
    }

    private function sender(): SenderSettings
    {
        $points = $this->createStub(SenderPoints::class);
        $points->method('receivingCity')->willReturnCallback(static fn(string $id): ?string => $id === self::RECEIVING ? self::CITY : null);

        return new SenderSettings($this->settings, $points);
    }

    /** @return array<string, string> */
    private static function post(array $override = []): array
    {
        return $override + [
            'sender_last_name' => ' Петренко ',
            'sender_first_name' => 'Оксана',
            'sender_middle_name' => '',
            'sender_phone' => '+38 (050) 111-22-33',
            'sender_point_id' => strtoupper(self::RECEIVING),
        ];
    }

    public function testPointMustReceive(): void
    {
        self::assertSame(['sender_point_id' => 'sviat__rozetka__sender_err_point'], $this->sender()->save(self::post(['sender_point_id' => self::GIVE_OUT_ONLY])));
        self::assertSame([], $this->settings->values);

        self::assertSame([], $this->sender()->save(self::post()));
        self::assertSame(self::RECEIVING, $this->settings->values[ShippingConfig::SENDER_POINT_ID]);
        self::assertSame(self::CITY, $this->settings->values[ShippingConfig::SENDER_CITY_ID], 'the city comes from the point');
    }

    public function testPhoneNormalized(): void
    {
        $this->sender()->save(self::post());
        self::assertSame('380501112233', $this->settings->values[ShippingConfig::SENDER_PHONE]);

        self::assertSame(['sender_phone' => 'sviat__rozetka__sender_err_phone'], $this->sender()->validate(self::post(['sender_phone' => '044 222 33 44'])));
    }

    public function testMiddleNameOptional(): void
    {
        self::assertSame([], $this->sender()->save(self::post()));
        self::assertSame('Петренко', $this->settings->values[ShippingConfig::SENDER_LAST_NAME]);
        self::assertSame('', $this->settings->values[ShippingConfig::SENDER_MIDDLE_NAME]);

        $errors = $this->sender()->validate(self::post(['sender_last_name' => '', 'sender_first_name' => str_repeat('я', 65), 'sender_middle_name' => str_repeat('я', 65)]));
        self::assertSame([
            'sender_last_name' => 'sviat__rozetka__sender_err_last_name',
            'sender_first_name' => 'sviat__rozetka__sender_err_first_name',
            'sender_middle_name' => 'sviat__rozetka__sender_err_middle_name',
        ], $errors);
    }

    public function testReadGivesSavedValues(): void
    {
        $this->sender()->save(self::post(['sender_middle_name' => 'Іванівна']));

        self::assertSame([
            'last_name' => 'Петренко', 'first_name' => 'Оксана', 'middle_name' => 'Іванівна',
            'phone' => '380501112233', 'point_id' => self::RECEIVING, 'city_id' => self::CITY,
        ], $this->sender()->read());
    }
}
