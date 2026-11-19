<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Quote;

/**
 * Ставить ціну модуля на спосіб доставки в кошику так само, як ядро в DeliveriesHelper::getCartDeliveriesList,
 * щоб теми показували її, нічого не знаючи про модуль.
 */
final class DeliveryPriceView
{
    /**
     * @param callable(float): string $convert Money::convert у валюту відвідувача
     * @param array{free: string, separate: string, receiver: string, receiver_plain: string} $texts receiver містить %s для суми
     */
    public static function apply(object $delivery, DeliveryQuote $quote, float $cartTotal, callable $convert, string $sign, array $texts): void
    {
        // Уже безкоштовна (free_from або промоакція раніше в ланцюжку): рішення не наше.
        if (!empty($delivery->is_free_delivery)) {
            return;
        }
        $delivery->total_price_with_delivery = $cartTotal;
        if ($quote->source === 'free') {
            $delivery->is_free_delivery = true;
            $delivery->price = 0.0;
            $delivery->delivery_price_text = $texts['free'];

            return;
        }
        $delivery->is_free_delivery = false;
        if ($quote->source === 'receiver') {
            $delivery->price = 0.0;
            $delivery->delivery_price_text = $quote->receiverPrice === null
                ? $texts['receiver_plain']
                : sprintf($texts['receiver'], $convert($quote->receiverPrice) . ' ' . $sign);

            return;
        }
        $delivery->price = $quote->price;
        $delivery->delivery_price_text = $convert($quote->price) . ' ' . $sign . (!empty($delivery->separate_payment) ? ', ' . $texts['separate'] : '');
        $delivery->total_price_with_delivery = $cartTotal + $quote->price;
    }
}
