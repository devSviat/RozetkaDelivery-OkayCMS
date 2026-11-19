<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Quote;

/** Ціна доставки для покупця. API ціни не має, тож це завжди рішення налаштувань, а не відповідь Rozetka. */
final readonly class DeliveryQuote
{
    public function __construct(
        /** Що йде в замовлення, у валюті розрахунку (гривні в TariffPrice, основна валюта після CartQuotes). */
        public float $price,
        /** tariff | free | receiver */
        public string $source,
        /** Скільки орієнтовно заплатить одержувач у точці; null — кошик цього не показує. */
        public ?float $receiverPrice = null,
    ) {
    }
}
