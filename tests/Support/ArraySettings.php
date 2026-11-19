<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

use Okay\Core\Settings;

/** Налаштування ядра в масиві. */
final class ArraySettings extends Settings
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values = [])
    {
    }

    public function get($param)
    {
        return $this->values[$param] ?? null;
    }

    public function set($param, $value)
    {
        $this->values[$param] = $value;
    }
}
