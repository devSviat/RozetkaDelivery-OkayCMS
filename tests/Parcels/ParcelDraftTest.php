<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

require_once __DIR__ . '/../Support/DraftFixtures.php';

use Modules\Sviat\RozetkaDelivery\Support\DraftFixtures as F;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class ParcelDraftTest extends TestCase
{
    public function testBodyShape(): void
    {
        $body = F::draft(['order' => F::order(['paid' => 1])])->body();

        self::assertSame('dept-dept', $body['type']);
        self::assertSame(1, $body['places']);
        self::assertSame('receiver', $body['delivery_payer']);
        self::assertSame(0.0, $body['cost']);
        self::assertSame(1000.0, $body['insurance_cost']);
        self::assertSame(['weight' => 1.5, 'length' => 30, 'width' => 20, 'height' => 15, 'volume' => 0.009], $body['params']);
        self::assertSame('Замовлення №77', $body['description']);
        self::assertSame([
            'city' => F::CITY, 'department' => F::POINT, 'last_name' => 'Тестова', 'first_name' => 'Олена', 'phone' => ['380501112233'],
        ], $body['recipient']);
        self::assertSame([
            'city' => F::SENDER_CITY, 'department' => F::SENDER_POINT, 'last_name' => 'Відправник', 'first_name' => 'Іван', 'phone' => ['380671112233'],
        ], $body['sender']);
        foreach (['carrier', 'has_active_insurance', 'declared_insurance_cost', 'address', 'name', 'visible_id'] as $absent) {
            self::assertArrayNotHasKey($absent, $body, $absent);
        }
        self::assertSame([], F::draft(['order' => F::order(['paid' => 1])])->errors());
    }

    public function testNetworkLimits(): void
    {
        $paid = F::order(['paid' => 1]);
        self::assertContains('sviat__rozetka__parcel_err_weight', F::keys(F::draft(['order' => $paid])->withForm(['weight' => '15.5'])));
        self::assertNotContains('sviat__rozetka__parcel_err_weight', F::keys(F::draft(['order' => $paid])->withForm(['weight' => '15'])));
        self::assertContains('sviat__rozetka__parcel_err_weight', F::keys(F::draft(['order' => $paid, 'point' => F::point(['max_weight' => '10.000'])])->withForm(['weight' => '11'])));
        self::assertContains('sviat__rozetka__parcel_err_sides', F::keys(F::draft(['order' => $paid])->withForm(['length' => '121'])));
        // 60×50×41 / 4000 = 30.75 кг об'ємної ваги.
        self::assertContains('sviat__rozetka__parcel_err_volume', F::keys(F::draft(['order' => $paid])->withForm(['length' => '60', 'width' => '50', 'height' => '41'])));
        self::assertContains('sviat__rozetka__parcel_err_declared_limit', F::keys(F::draft(['order' => $paid])->withForm(['declared_value' => '30001'])));
        self::assertContains('sviat__rozetka__parcel_err_declared', F::keys(F::draft(['order' => $paid])->withForm(['declared_value' => '0'])));
    }

    public function testFormEditsFields(): void
    {
        $draft = F::draft(['order' => F::order(['paid' => 1])])->withForm([
            'last_name' => ' Інша ', 'first_name' => 'Марія', 'middle_name' => 'Петрівна', 'phone' => '0931234567',
            'payer' => 'sender', 'description' => 'Посуд', 'weight' => '2,25',
        ]);

        self::assertSame([], $draft->errors());
        self::assertSame('manual', $draft->payerReason);
        $body = $draft->body();
        self::assertSame(['last_name' => 'Інша', 'first_name' => 'Марія', 'middle_name' => 'Петрівна', 'phone' => ['380931234567']], array_diff_key($body['recipient'], ['city' => 1, 'department' => 1]));
        self::assertSame('sender', $body['delivery_payer']);
        self::assertSame(2.25, $body['params']['weight']);
    }

    public function testBadFormValuesAreErrors(): void
    {
        $paid = F::order(['paid' => 1]);
        self::assertContains('sviat__rozetka__parcel_err_phone', F::keys(F::draft(['order' => $paid])->withForm(['phone' => '0442223344'])));
        self::assertContains('sviat__rozetka__parcel_err_name', F::keys(F::draft(['order' => $paid])->withForm(['first_name' => ' '])));
        self::assertContains('sviat__rozetka__parcel_err_payer', F::keys(F::draft(['order' => $paid])->withForm(['payer' => 'other'])));
        self::assertContains('sviat__rozetka__parcel_err_weight', F::keys(F::draft(['order' => $paid])->withForm(['weight' => 'abc'])));
        self::assertContains('sviat__rozetka__parcel_err_point', F::keys(F::draft(['order' => $paid, 'point' => F::point(['can_give_out' => 0])])));
        self::assertContains('sviat__rozetka__parcel_err_sender', F::keys(F::draft(['order' => $paid, 'senderPoint' => null])));
        self::assertContains('sviat__rozetka__parcel_err_sender', F::keys(F::draft(['order' => $paid, 'config' => F::config([ShippingConfig::SENDER_PHONE => ''])])));
    }

    public function testCodAmountEditable(): void
    {
        $draft = F::draft(['cod' => true])->withForm(['cod_amount' => '400']);
        self::assertSame(400.0, $draft->body()['cost']);
        self::assertSame(21.0, $draft->codFeeEstimate);

        $zero = F::draft(['cod' => true])->withForm(['cod_amount' => '0']);
        self::assertTrue($zero->awaitsPayment(), 'removing COD of an unpaid order means the point gives it out free');
        self::assertContains('sviat__rozetka__parcel_err_cod', F::keys(F::draft(['cod' => true])->withForm(['cod_amount' => '-1'])));
        self::assertContains('sviat__rozetka__parcel_err_cod_over_declared', F::keys(F::draft(['cod' => true])->withForm(['declared_value' => '300'])));
    }
}
