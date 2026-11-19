<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Orders;

use Okay\Modules\Sviat\RozetkaDelivery\Quote\FreeDelivery;

/**
 * Що відбувається з ціною доставки замовлення, коли менеджер змінює його точку. Тариф від точки не залежить,
 * тож нова ціна лише пропонується кнопкою: ручну ціну менеджера зміна точки не переписує, оплачене
 * замовлення не переоцінюється.
 */
final class OrderPriceRule
{
    /** @return array{action: 'keep'|'free'|'show'|'offer', price: ?float} */
    public static function decide(object $order, object $delivery, ?float $tariff): array
    {
        if ($tariff === null) {
            return ['action' => 'keep', 'price' => null];
        }
        $total = (float)($order->total_price ?? 0);
        $goods = empty($order->separate_delivery) ? $total - (float)($order->delivery_price ?? 0) : $total;
        if (FreeDelivery::applies($delivery, $goods)) {
            return ['action' => 'free', 'price' => 0.0];
        }
        if (round($tariff, 2) === round((float)($order->delivery_price ?? 0), 2)) {
            return ['action' => 'keep', 'price' => $tariff];
        }
        if (!empty($order->paid)) {
            return ['action' => 'show', 'price' => $tariff];
        }

        return ['action' => 'offer', 'price' => $tariff];
    }
}
