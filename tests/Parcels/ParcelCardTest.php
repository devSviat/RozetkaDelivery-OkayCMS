<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelCard;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use PHPUnit\Framework\TestCase;

class ParcelCardTest extends TestCase
{
    public static function row(array $override = []): object
    {
        return (object)($override + [
            'order_id' => 77, 'track_id' => '101123456789', 'visible_id' => '77-3', 'state' => 'created',
            'status_code' => 40030, 'status_raw' => '40030', 'status_group' => 'arrived', 'partial' => 0,
            'event_name' => 'Відправлення у відділенні', 'event_at' => '2027-01-16 07:00:00', 'arrived_at' => '2027-01-16 07:00:00',
            'expiration_date' => '2027-01-26 18:00:00', 'delivery_date' => '2027-01-16 16:00:00',
            'shipping_cost' => '49.00', 'payment_fee' => null, 'cod_amount' => '0.00', 'declared_value' => '1000.00', 'has_paid' => null,
            'delivery_payer' => 'receiver', 'payer_reason' => 'setting', 'point_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'reception_id' => null,
            'details' => '{"recipient_name":"Тестова Олена","point_title":"Точка видачі №1","description":"Замовлення №77","weight_kg":1.5,"length":30,"width":20,"height":15,"volume":0.009}',
            'history' => '[{"code":10010,"raw":"10010","name":"Заплановано","at":"2027-01-15 10:00:00"},{"code":40030,"raw":"40030","name":"Відправлення у відділенні","at":"2027-01-16 07:00:00"}]',
            'created_at' => '2027-01-15 10:00:00', 'checked_at' => '2027-01-16 08:00:00',
        ]);
    }

    public function testFullCard(): void
    {
        $card = ParcelCard::fromRow(self::row(), CodConfig::FISCAL_UNKNOWN);

        self::assertSame('101123456789', $card['trackId']);
        self::assertSame('sviat__rozetka__parcel_group_arrived', $card['statusKey']);
        self::assertSame(['name' => 'Відправлення у відділенні', 'at' => '16.01.2027 07:00'], $card['event']);
        self::assertSame('Тестова Олена', $card['recipient']);
        self::assertSame('Точка видачі №1', $card['point']);
        self::assertSame('49.00', $card['shippingCost']);
        self::assertSame('sviat__rozetka__parcel_pays_receiver', $card['payerKey']);
        self::assertSame('sviat__rozetka__parcel_reason_setting', $card['payerReasonKey']);
        self::assertSame('1000.00', $card['declared']);
        self::assertSame('16.01.2027', $card['deliveryDate']);
        self::assertSame('26.01.2027 18:00', $card['expirationDate']);
        self::assertSame(['weight' => '1.5', 'sides' => '30×20×15', 'volume' => '0.009', 'description' => 'Замовлення №77', 'visibleId' => '77-3'], $card['more']);
        self::assertSame(['Відправлення у відділенні', 'Заплановано'], array_column($card['history'], 'name'));
        self::assertNull($card['trackUrl'], 'no public tracking URL with the number is known');
    }

    public function testOnlyRealFacts(): void
    {
        $card = ParcelCard::fromRow((object)['track_id' => '101123456789', 'state' => 'created', 'status_group' => 'created'], CodConfig::FISCAL_UNKNOWN);

        foreach (['recipient', 'point', 'shippingCost', 'payerKey', 'payerReasonKey', 'cod', 'declared', 'deliveryDate', 'expirationDate', 'event', 'created', 'checked'] as $empty) {
            self::assertNull($card[$empty], $empty);
        }
        self::assertSame([], $card['more']);
        self::assertSame([], $card['history']);
        self::assertFalse($card['fiscalBanner']);

        $received = ParcelCard::fromRow(self::row(['status_group' => 'received']), CodConfig::FISCAL_UNKNOWN);
        self::assertNull($received['deliveryDate'], 'a planned date says nothing after delivery');
        self::assertNull($received['expirationDate']);
    }

    public function testCodLine(): void
    {
        $card = ParcelCard::fromRow(self::row(['cod_amount' => '1000.00', 'payment_fee' => '30.00', 'has_paid' => 1]), CodConfig::FISCAL_SHOP);

        self::assertSame(['amount' => '1000.00', 'fee' => '30.00', 'paid' => true], $card['cod']);
        self::assertSame(['amount' => '1000.00', 'fee' => null, 'paid' => null], ParcelCard::fromRow(self::row(['cod_amount' => '1000.00']), CodConfig::FISCAL_SHOP)['cod']);
        self::assertSame(false, ParcelCard::fromRow(self::row(['cod_amount' => '5', 'has_paid' => 0]), CodConfig::FISCAL_SHOP)['cod']['paid']);
    }

    public function testUnknownFiscalBanner(): void
    {
        self::assertTrue(ParcelCard::fromRow(self::row(['cod_amount' => '1000.00']), CodConfig::FISCAL_UNKNOWN)['fiscalBanner']);
        self::assertFalse(ParcelCard::fromRow(self::row(['cod_amount' => '1000.00']), CodConfig::FISCAL_SHOP)['fiscalBanner']);
        self::assertFalse(ParcelCard::fromRow(self::row(), CodConfig::FISCAL_UNKNOWN)['fiscalBanner'], 'no COD');
    }

    public function testPartialAndUnknownGroup(): void
    {
        self::assertTrue(ParcelCard::fromRow(self::row(['status_group' => 'received', 'partial' => 1]), CodConfig::FISCAL_SHOP)['partial']);
        self::assertNull(ParcelCard::fromRow(self::row(['status_group' => '<b>x</b>']), CodConfig::FISCAL_SHOP)['statusKey']);
    }

    public function testReceptionLine(): void
    {
        $card = ParcelCard::fromRow(self::row(['status_group' => 'created', 'reception_id' => 555]), CodConfig::FISCAL_SHOP, (object)['reception_id' => 555, 'status' => 'printed']);

        self::assertSame(['number' => '555', 'statusKey' => 'sviat__rozetka__reception_status_printed'], $card['reception']);
        self::assertFalse($card['receptionHint']);
        self::assertSame(['number' => '555', 'statusKey' => null], ParcelCard::fromRow(self::row(['reception_id' => 555]), CodConfig::FISCAL_SHOP)['reception']);
    }

    public function testReceptionHintAfterDay(): void
    {
        $created = strtotime('2027-01-15 10:00:00');
        $row = self::row(['status_group' => 'created', 'created_at' => '2027-01-15 10:00:00']);

        self::assertTrue(ParcelCard::fromRow($row, CodConfig::FISCAL_SHOP, null, $created + 86401)['receptionHint']);
        self::assertFalse(ParcelCard::fromRow($row, CodConfig::FISCAL_SHOP, null, $created + 3600)['receptionHint']);
        self::assertFalse(ParcelCard::fromRow(self::row(['created_at' => '2027-01-15 10:00:00']), CodConfig::FISCAL_SHOP, null, $created + 86401 * 3)['receptionHint'], 'already accepted');
    }
}
