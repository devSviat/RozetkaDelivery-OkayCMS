<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Quote;

/**
 * Ціна доставки з налаштувань модуля й прапорців способу доставки ядра, як у NovaPoshta:
 * поріг безкоштовної доставки ядра → 0; «оплата окремо» → одержувач платить у точці, у замовлення 0;
 * інакше — `cart_price` (тариф договору). Ціна способу в ядрі не використовується.
 */
final class TariffPrice
{
    public static function quote(object $delivery, ShippingConfig $config, float $cartTotal): DeliveryQuote
    {
        if (FreeDelivery::applies($delivery, $cartTotal)) {
            return new DeliveryQuote(0.0, 'free');
        }
        if (!empty($delivery->separate_payment)) {
            return new DeliveryQuote(0.0, 'receiver', $config->receiverPriceNote ? $config->cartPrice : null);
        }

        return new DeliveryQuote($config->cartPrice, 'tariff');
    }
}
