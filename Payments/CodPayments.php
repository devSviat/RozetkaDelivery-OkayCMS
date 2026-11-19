<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Payments;

use Okay\Core\EntityFactory;
use Okay\Entities\PaymentsEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;

/**
 * Способи оплати, позначені «післяплата ROZETKA». Вибір такого способу в кошику — це вибір покупцем
 * післяплати; окремого чекбокса в кошику немає. Поки прапорець модуля вимкнений, позначок не існує.
 */
final class CodPayments
{
    /** Власне поле в таблиці оплат ядра. */
    public const string FIELD = 'sviat__rozetka__cod';

    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly CodSettings $codSettings,
    ) {
    }

    /** «Manual» у селекті типу оплати: ядро зберігає його як рядок 'null'. */
    public static function isManual(mixed $module): bool
    {
        return $module === null || $module === '' || $module === 'null';
    }

    /** Позначка способу оплати сама по собі, без прапорця модуля. */
    public static function marked(object $method): bool
    {
        return self::isManual($method->module ?? null) && (int)($method->{self::FIELD} ?? 0) === 1;
    }

    /** @return list<object> способи оплати, які кошик вважає післяплатою ROZETKA */
    public function flagged(): array
    {
        if (!$this->codSettings->read()->enabled) {
            return [];
        }
        $methods = $this->entityFactory->get(PaymentsEntity::class)->noLimit()->find() ?: [];

        return array_values(array_filter($methods, self::marked(...)));
    }

    /** Онлайн-оплати (будь-який модуль оплати) ніколи не є післяплатою, хоч би що було в колонці. */
    public function isCod(int $paymentId): bool
    {
        if ($paymentId <= 0 || !$this->codSettings->read()->enabled) {
            return false;
        }
        $method = $this->entityFactory->get(PaymentsEntity::class)->get($paymentId);

        return $method && self::marked($method);
    }
}
