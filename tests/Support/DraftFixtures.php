<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartMetrics;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDraft;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDrafts;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;

/** Вигадане замовлення, точки й налаштування для чернетки ЕН. */
final class DraftFixtures
{
    public const string POINT = 'aaaaaaaa-0000-4000-8000-000000000001';
    public const string CITY = 'cccccccc-0000-4000-8000-000000000001';
    public const string SENDER_POINT = 'aaaaaaaa-0000-4000-8000-000000000009';
    public const string SENDER_CITY = 'cccccccc-0000-4000-8000-000000000009';

    public static function order(array $override = []): object
    {
        return (object)($override + [
            'id' => 77, 'name' => 'Олена', 'last_name' => 'Тестова', 'phone' => '+38 (050) 111-22-33',
            'paid' => 0, 'total_price' => 1000.0, 'delivery_price' => 0.0, 'separate_delivery' => 1, 'payment_method_id' => 3,
        ]);
    }

    public static function point(array $override = []): object
    {
        return (object)($override + [
            'id' => self::POINT, 'city_id' => self::CITY, 'kind' => 'pickup', 'type_id' => 2, 'title' => 'Точка видачі №1',
            'max_weight' => null, 'max_volume_weight' => null, 'max_length' => null, 'max_cost' => null,
            'can_receive' => 1, 'can_give_out' => 1, 'prepay_only' => 0,
        ]);
    }

    public static function senderPoint(array $override = []): object
    {
        return self::point($override + ['id' => self::SENDER_POINT, 'city_id' => self::SENDER_CITY, 'title' => 'Точка прийому №9']);
    }

    /** @param array<string, mixed> $settings */
    public static function config(array $settings = []): ParcelConfig
    {
        $values = $settings + [
            ShippingConfig::SENDER_LAST_NAME => 'Відправник',
            ShippingConfig::SENDER_FIRST_NAME => 'Іван',
            ShippingConfig::SENDER_PHONE => '0671112233',
            ShippingConfig::SENDER_POINT_ID => self::SENDER_POINT,
            ShippingConfig::SENDER_CITY_ID => self::SENDER_CITY,
            ShippingConfig::RECEIVER_PAY => '1',
            ShippingConfig::DECLARE_TOTAL => '1',
            ShippingConfig::DESCRIPTION => 'Замовлення №{order}',
            CodConfig::ENABLED => '1',
        ];

        return ParcelConfig::fromSettings(static fn(string $key): mixed => $values[$key] ?? null);
    }

    public static function metrics(float $goodsUah = 1000.0, float $weight = 1.5): CartMetrics
    {
        return new CartMetrics($weight, false, $goodsUah, $goodsUah);
    }

    /** @param array<string, mixed> $o order/snapshot/point/config/metrics/totalUah/cod/due/mass */
    public static function draft(array $o = []): ParcelDraft
    {
        return ParcelDrafts::build(
            $o['order'] ?? self::order(),
            $o['snapshot'] ?? (object)['price_source' => 'receiver'],
            array_key_exists('point', $o) ? $o['point'] : self::point(),
            array_key_exists('senderPoint', $o) ? $o['senderPoint'] : self::senderPoint(),
            $o['config'] ?? self::config(),
            $o['metrics'] ?? self::metrics(),
            $o['totalUah'] ?? 1000.0,
            $o['cod'] ?? false,
            $o['due'] ?? null,
            $o['mass'] ?? false,
        );
    }

    /** @return list<string> мовні ключі помилок */
    public static function keys(ParcelDraft $draft): array
    {
        return array_column($draft->errors(), 'key');
    }
}
