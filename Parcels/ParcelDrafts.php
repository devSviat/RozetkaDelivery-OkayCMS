<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartMetrics;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PointFit;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\UahTotal;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderParcel;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;

/** Будує першу чернетку ЕН замовлення. */
final class ParcelDrafts
{
    private const string P = 'sviat__rozetka__';

    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly OrderDeliveryLines $deliveryLines,
        private readonly OrderDeliveryStore $snapshots,
        private readonly OrderParcel $parcel,
        private readonly UahTotal $uahTotal,
        private readonly CodPayments $codPayments,
        private readonly Settings $settings,
        private readonly AmountDue $amountDue,
    ) {
    }

    /** @return ParcelDraft|string чернетка або мовний ключ того, чого бракує замовленню */
    public function forOrder(int $orderId, bool $mass = false): ParcelDraft|string
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);
        if (!$order) {
            return self::P . 'parcel_err_no_order';
        }
        if ($this->deliveryLines->ownDelivery($order) === null) {
            return self::P . 'parcel_err_not_own';
        }
        $snapshot = $this->snapshots->forOrder($orderId);
        // Точку беремо з довідника, а не зі знімка: ліміти, видача й «лише передоплата» змінюються після замовлення.
        $point = $this->point((string)($snapshot->point_id ?? ''));
        if ($snapshot === null || $point === null) {
            return self::P . 'parcel_err_no_point';
        }
        $config = ParcelConfig::fromSettings($this->settings->get(...));
        $metrics = $this->parcel->metrics($order);
        $orderTotalUah = $this->uahTotal->metrics((object)['purchases' => [], 'total_price' => (float)($order->total_price ?? 0)])->totalUah;
        $missing = self::uahMissing($metrics->totalUah, $orderTotalUah);
        if ($missing !== null) {
            return $missing;
        }

        return self::build(
            $order,
            $snapshot,
            $point,
            $this->point($config->shipping->senderPointId),
            $config,
            $metrics,
            (float)$orderTotalUah,
            // Покупець міг змінити оплату після оформлення: знімок кошика (`cod`) тут не джерело.
            $this->codPayments->isCod((int)($order->payment_method_id ?? 0)),
            fn(float $totalUah): float => $this->amountDue->onPickup($order, $totalUah),
            $mass,
        );
    }

    /** Без гривні не відомі ні оголошена, ні сума післяплати. */
    public static function uahMissing(?float $goodsUah, ?float $orderTotalUah): ?string
    {
        return $goodsUah === null || $orderTotalUah === null ? self::P . 'parcel_err_no_uah' : null;
    }

    /**
     * @param object $snapshot рядок OrderDeliveryEntity (`price_source`)
     * @param float $orderTotalUah сума замовлення з доставкою, коли вона в сумі, у гривнях
     * @param bool $paymentIsCod поточний спосіб оплати замовлення — післяплата ROZETKA (CodPayments::isCod)
     * @param ?\Closure(float): float $amountDue що лишається стягнути, із суми замовлення в гривнях
     */
    public static function build(
        object $order,
        object $snapshot,
        ?object $point,
        ?object $senderPoint,
        ParcelConfig $config,
        CartMetrics $metrics,
        float $orderTotalUah,
        bool $paymentIsCod,
        ?\Closure $amountDue = null,
        bool $mass = false,
    ): ParcelDraft {
        $shipping = $config->shipping;
        $paid = !empty($order->paid);
        $chained = $amountDue ? $amountDue($orderTotalUah) : $orderTotalUah;
        // max(0, NAN) у PHP дає 0, і зламана ланка зробила б неоплачене замовлення «покритим авансом».
        $due = max(0.0, round(is_finite($chained) ? $chained : $orderTotalUah, 2));
        $codAllowed = $paymentIsCod && !$paid && $due > 0;
        $codAmount = $codAllowed ? $due : 0.0;
        // Оголошена не менша за переказ: інакше Rozetka не відшкодує повну суму, яку мала стягнути.
        $declared = round(max(PointFit::declared($shipping, (float)$metrics->totalUah), $codAmount), 2);
        [$payer, $reason] = self::payer($order, $snapshot, $shipping->receiverPay);
        $name = PersonName::split((string)($order->last_name ?? ''), (string)($order->name ?? ''))
            ?? ['last' => '', 'first' => PersonName::clean((string)($order->name ?? '')), 'middle' => ''];
        $phone = (string)($order->phone ?? '');

        return new ParcelDraft(
            (int)$order->id,
            $name['last'],
            $name['first'],
            $name['middle'],
            RozetkaPhone::normalize($phone) ?? $phone,
            $point,
            $senderPoint,
            $config,
            max(0.001, round($metrics->knownWeight > 0 ? $metrics->knownWeight : $shipping->defaultWeightKg, 3)),
            $shipping->lengthCm,
            $shipping->widthCm,
            $shipping->heightCm,
            $declared,
            $payer,
            $reason,
            $codAllowed,
            $codAmount,
            $codAmount > 0 ? $config->cod->estimateFee($codAmount) : null,
            $paid,
            $due,
            false,
            $mass,
            str_replace('{order}', (string)(int)$order->id, $shipping->description),
        );
    }

    /**
     * Правило NovaPoshta поверх налаштування: доставку, яку магазин уже взяв у сумі чи пообіцяв безкоштовною,
     * одержувач у точці не платить.
     *
     * @return array{0: string, 1: string} платник і причина
     */
    public static function payer(object $order, object $snapshot, bool $receiverPaySetting): array
    {
        $inTotal = empty($order->separate_delivery);
        $price = (float)($order->delivery_price ?? 0);
        if ($inTotal && $price > 0) {
            return ['sender', 'in_total'];
        }
        // Лише позначка кошика: замовлення з адмінки чи інших джерел з нульовою доставкою безкоштовної не обіцяли.
        $source = $snapshot->price_source ?? null;
        if ($source === 'free' || ($source === 'external' && $price <= 0)) {
            return ['sender', 'free'];
        }

        return [$receiverPaySetting ? 'receiver' : 'sender', 'setting'];
    }

    private function point(string $id): ?object
    {
        if ($id === '') {
            return null;
        }
        $row = $this->entityFactory->get(PointEntity::class)->get(strtolower($id));

        return is_object($row) ? $row : null;
    }
}
