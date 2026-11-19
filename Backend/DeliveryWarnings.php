<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Backend;

use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;

/** Попередження на картці способу доставки модуля; ключі — частини мовних ключів `sviat__rozetka__dwarn_{ключ}_*`. */
final class DeliveryWarnings
{
    /** Ціна з налаштувань, а не з API: після зміни тарифів її треба оновити вручну. */
    public const string STATIC_PRICE = 'static_price';
    /** Готівка чи переказ без післяплати: точка видасть товар, нічого не стягнувши. */
    public const string MANUAL_WITHOUT_COD = 'manual_without_cod';

    /**
     * @param list<object> $payments способи оплати, прив'язані до способу доставки
     * @return list<string>
     */
    public static function forDelivery(object $delivery, array $payments, CodConfig $cod): array
    {
        $warnings = [self::STATIC_PRICE];
        foreach ($payments as $payment) {
            if (CodPayments::isManual($payment->module ?? null) && (!$cod->enabled || !CodPayments::marked($payment))) {
                $warnings[] = self::MANUAL_WITHOUT_COD;
                break;
            }
        }

        return $warnings;
    }
}
