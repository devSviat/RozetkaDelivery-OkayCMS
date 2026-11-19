<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

require_once __DIR__ . '/../Support/DraftFixtures.php';

use Modules\Sviat\RozetkaDelivery\Support\DraftFixtures as F;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDetails;
use PHPUnit\Framework\TestCase;

class ParcelDetailsTest extends TestCase
{
    public function testOnlyWhitelist(): void
    {
        $details = ParcelDetails::fromDraft(F::draft(['cod' => true]), [
            'track_id' => '101123456789',
            'payment_fee_details' => ['percentage' => 1.5, 'additional' => 15, 'payment_fee_limit' => 0, 'secret' => 'x'],
            'recipient' => ['phone' => ['380501112233']],
            'unexpected' => '<script>',
        ]);

        self::assertSame([
            'recipient_name' => 'Тестова Олена',
            'point_title' => 'Точка видачі №1',
            'description' => 'Замовлення №77',
            'weight_kg' => 1.5,
            'length' => 30,
            'width' => 20,
            'height' => 15,
            'volume' => 0.009,
            'fee_percentage' => 1.5,
            'fee_additional' => 15.0,
            'fee_limit' => 0.0,
        ], $details);
        $json = (string)ParcelDetails::encode($details);
        self::assertStringNotContainsString('380', $json);
        self::assertSame($details, ParcelDetails::decode($json));
    }

    public function testCorruptJsonIsEmpty(): void
    {
        self::assertSame([], ParcelDetails::decode('{corrupt'));
        self::assertSame([], ParcelDetails::decode('[1,2]'));
        self::assertSame([], ParcelDetails::decode(null));
        self::assertSame(['description' => 'x'], ParcelDetails::decode('{"description":"x","phone":"380501112233","weight_kg":"abc"}'));
    }
}
