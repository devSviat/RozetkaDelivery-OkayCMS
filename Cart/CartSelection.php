<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Cart;

/** Перевірені місто й точка для одного замовлення або нічого, коли доставка не цього модуля. */
final readonly class CartSelection
{
    public function __construct(
        public ?object $delivery,
        public ?object $city,
        public ?object $point,
        /** Спосіб оплати замовлення — післяплата ROZETKA (з урахуванням прапорця модуля). */
        public bool $cod = false,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public function applies(): bool
    {
        return $this->city !== null && $this->point !== null;
    }
}
