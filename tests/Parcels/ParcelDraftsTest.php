<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

require_once __DIR__ . '/../Support/DraftFixtures.php';

use Modules\Sviat\RozetkaDelivery\Support\DraftFixtures as F;
use Okay\Core\Modules\Extender\ChainExtender;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\AmountDue;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class ParcelDraftsTest extends TestCase
{
    protected function tearDown(): void
    {
        (new \ReflectionProperty(ChainExtender::class, 'triggers'))->setValue(null, []);
    }

    public function testInTotalMakesSenderPay(): void
    {
        $draft = F::draft(['order' => F::order(['separate_delivery' => 0, 'delivery_price' => 49.0, 'total_price' => 1049.0]), 'snapshot' => (object)['price_source' => 'tariff']]);

        self::assertSame('sender', $draft->payer);
        self::assertSame('in_total', $draft->payerReason);
    }

    public function testFreeMakesSenderPay(): void
    {
        $free = F::draft(['order' => F::order(['separate_delivery' => 0, 'delivery_price' => 0.0]), 'snapshot' => (object)['price_source' => 'free']]);
        self::assertSame(['sender', 'free'], [$free->payer, $free->payerReason]);

        $promo = F::draft(['order' => F::order(['separate_delivery' => 0, 'delivery_price' => 0.0]), 'snapshot' => (object)['price_source' => 'external']]);
        self::assertSame(['sender', 'free'], [$promo->payer, $promo->payerReason], 'a promo that made delivery free in the total');
    }

    /** «Безкоштовно» лише за позначкою кошика: замовлення з адмінки чи інших джерел з нульовою доставкою нічого не обіцяли. */
    public function testFreeOnlyByTheCartMark(): void
    {
        $promo = F::draft(['order' => F::order(['separate_delivery' => 1, 'delivery_price' => 0.0]), 'snapshot' => (object)['price_source' => 'external']]);
        self::assertSame(['sender', 'free'], [$promo->payer, $promo->payerReason], 'a promo, delivery paid apart');

        $foreign = F::draft(['order' => F::order(['separate_delivery' => 0, 'delivery_price' => 0.0]), 'snapshot' => (object)['price_source' => null]]);
        self::assertSame(['receiver', 'setting'], [$foreign->payer, $foreign->payerReason], 'admin or another source, price 0 in the total');

        $changed = F::draft(['order' => F::order(['separate_delivery' => 1, 'delivery_price' => 80.0]), 'snapshot' => (object)['price_source' => 'external']]);
        self::assertSame('receiver', $changed->payer, 'a promo that changed but did not zero the price');
    }

    public function testSettingWhenSeparate(): void
    {
        $receiver = F::draft();
        self::assertSame(['receiver', 'setting'], [$receiver->payer, $receiver->payerReason]);

        $sender = F::draft(['config' => F::config([ShippingConfig::RECEIVER_PAY => '0'])]);
        self::assertSame(['sender', 'setting'], [$sender->payer, $sender->payerReason]);
    }

    public function testDeclaredTotalOrFixed(): void
    {
        self::assertSame(1000.0, F::draft()->declaredValue);
        $fixed = F::draft(['config' => F::config([ShippingConfig::DECLARE_TOTAL => '0', ShippingConfig::DECLARED_VALUE => '300'])]);
        self::assertSame(300.0, $fixed->declaredValue);
    }

    public function testDeclaredAtLeastCod(): void
    {
        $draft = F::draft([
            'config' => F::config([ShippingConfig::DECLARE_TOTAL => '0', ShippingConfig::DECLARED_VALUE => '300']),
            'cod' => true,
            'totalUah' => 1049.0,
        ]);

        self::assertSame(1049.0, $draft->codAmount);
        self::assertSame(1049.0, $draft->declaredValue, 'declared is never below the COD transfer');
        self::assertSame(1049.0, $draft->body()['insurance_cost']);
    }

    public function testDeclaredOverPointLimitError(): void
    {
        $draft = F::draft(['point' => F::point(['max_cost' => '500.00'])]);

        self::assertContains('sviat__rozetka__parcel_err_declared_limit', F::keys($draft));
        self::assertSame(500.0, $draft->costLimit());
        self::assertNotContains('sviat__rozetka__parcel_err_declared_limit', F::keys(F::draft()), 'control: no point limit');
    }

    public function testAmountDueReducesCod(): void
    {
        $link = new \stdClass();
        $link->class = AdvanceLink::class;
        $link->method = 'reduce';
        (new \ReflectionProperty(ChainExtender::class, 'triggers'))->setValue(null, [AmountDue::class . '::onPickup' => [$link]]);
        $due = new AmountDue();
        $order = F::order();

        $draft = F::draft(['order' => $order, 'cod' => true, 'totalUah' => 2455.0, 'due' => static fn(float $total): float => $due->onPickup($order, $total)]);

        self::assertSame(1255.0, $draft->codAmount);
        self::assertSame(1255.0, $draft->body()['cost']);
    }

    public function testZeroDueMeansNoCodAndNotUnpaid(): void
    {
        $draft = F::draft(['cod' => true, 'due' => static fn(float $total): float => 0.0]);

        self::assertSame(0.0, $draft->codAmount);
        self::assertFalse($draft->cod());
        self::assertFalse($draft->awaitsPayment(), 'the advance covered everything');
        self::assertNotContains('sviat__rozetka__parcel_err_unpaid', F::keys($draft));
    }

    /** Зламана ланка AmountDue (NAN, INF) не робить замовлення «оплаченим»: сума лишається повною. */
    public function testNonFiniteDueKeepsFullAmount(): void
    {
        $nan = F::draft(['due' => static fn(float $total): float => NAN]);
        self::assertSame(1000.0, $nan->due);
        self::assertContains('sviat__rozetka__parcel_err_unpaid', F::keys($nan));

        self::assertSame(1000.0, F::draft(['cod' => true, 'due' => static fn(float $total): float => INF])->codAmount);
    }

    public function testUnpaidWithoutCodBlocked(): void
    {
        $draft = F::draft();

        self::assertTrue($draft->awaitsPayment());
        self::assertSame(0.0, $draft->body()['cost']);
        self::assertContains(['field' => 'release_unpaid', 'key' => 'sviat__rozetka__parcel_err_unpaid'], $draft->errors());
        self::assertNotContains('sviat__rozetka__parcel_err_unpaid', F::keys(F::draft(['order' => F::order(['paid' => 1])])), 'control: a paid order');
    }

    public function testReleaseUnpaidAllows(): void
    {
        $draft = F::draft()->withForm(['release_unpaid' => '1']);

        self::assertTrue($draft->releaseUnpaid);
        self::assertSame([], $draft->errors());
        self::assertFalse(F::draft()->withForm([])->releaseUnpaid, 'no default: an absent field is not a release');
    }

    public function testMassNeverReleases(): void
    {
        $draft = F::draft(['mass' => true])->withForm(['release_unpaid' => '1']);

        self::assertFalse($draft->releaseUnpaid);
        self::assertContains('sviat__rozetka__parcel_err_unpaid', F::keys($draft));
    }

    public function testCodToPrepayOnlyDraftError(): void
    {
        $partner = F::point(['kind' => 'partner', 'type_id' => 14, 'prepay_only' => 1]);

        self::assertContains('sviat__rozetka__parcel_err_cod_prepay_only', F::keys(F::draft(['point' => $partner, 'cod' => true])));
        self::assertNotContains('sviat__rozetka__parcel_err_cod_prepay_only', F::keys(F::draft(['point' => $partner, 'order' => F::order(['paid' => 1])])), 'control: prepaid');
        $edited = F::draft(['point' => $partner, 'order' => F::order(['paid' => 1]), 'cod' => true])->withForm(['cod_amount' => '100']);
        self::assertNotContains('sviat__rozetka__parcel_err_cod_prepay_only', F::keys($edited), 'a paid order has no COD to add');
    }

    public function testCodFlagOffSendsZero(): void
    {
        // CodPayments::isCod() з вимкненим прапорцем — false для кожного способу.
        $draft = F::draft(['cod' => false, 'config' => F::config([CodConfig::ENABLED => '0'])])->withForm(['cod_amount' => '500', 'release_unpaid' => '1']);

        self::assertSame(0.0, $draft->body()['cost']);
        self::assertNull($draft->codFeeEstimate);
    }

    public function testCodFromCurrentPaymentNotSnapshot(): void
    {
        $snapshotSaysCod = (object)['price_source' => 'receiver', 'cod' => 1, 'cod_fee' => 30.0];

        $switchedToCard = F::draft(['snapshot' => $snapshotSaysCod, 'cod' => false]);
        self::assertSame(0.0, $switchedToCard->codAmount);
        self::assertTrue($switchedToCard->awaitsPayment());

        $switchedToCod = F::draft(['snapshot' => (object)['price_source' => 'receiver', 'cod' => 0], 'cod' => true]);
        self::assertSame(1000.0, $switchedToCod->codAmount);
        self::assertSame(30.0, $switchedToCod->codFeeEstimate, '15 + 1.5 % of 1000');
    }

    public function testNoUahError(): void
    {
        self::assertSame('sviat__rozetka__parcel_err_no_uah', \Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDrafts::uahMissing(null, 1000.0));
        self::assertSame('sviat__rozetka__parcel_err_no_uah', \Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDrafts::uahMissing(1000.0, null));
        self::assertNull(\Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDrafts::uahMissing(1000.0, 1000.0));
    }
}

/** Ланка, як у Checkbox: аванс ланцюжка передоплати зменшує суму до сплати. */
final class AdvanceLink implements ExtensionInterface
{
    public function reduce(float $due, object $order, float $total): float
    {
        return $due - 1200.0;
    }
}
