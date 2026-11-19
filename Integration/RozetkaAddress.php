<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Integration;

/** `line` — назва точки зі знімка замовлення; `number` — номер точки, порожній, якщо Rozetka його не дає. */
final readonly class RozetkaAddress
{
    public function __construct(public string $city, public string $line, public string $number)
    {
    }
}
