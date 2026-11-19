<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Orders;

use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelStatus;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\TrackNumber;

/** Частини фільтра списку замовлень, які хаб Sviat/DeliveryFilter делегує цьому модулю. */
final class OrdersListFilter
{
    public const string NONE = 'none';

    /** @return ?string група з ParcelStatus::FILTER_GROUPS або null */
    public static function group(mixed $raw): ?string
    {
        return is_string($raw) && in_array($raw, ParcelStatus::FILTER_GROUPS, true) ? $raw : null;
    }

    /** @param int $moduleId id цього модуля: `none` — замовлення з його доставкою без ЕН */
    public static function byStatus(array $filter, mixed $group, int $moduleId): array
    {
        $group = self::group($group);
        if ($group === self::NONE) {
            $filter['rozetka_without_parcel'] = $moduleId;
        } elseif ($group !== null) {
            // Підзапит за status_group (OrdersEntityFilters), а не id кожного замовлення групи.
            $filter['rozetka_status_groups'] = [$group];
        }

        return $filter;
    }

    /** Номер ЕН, якщо текст пошуку ним є: пробіли й дефіси, як у SMS, не заважають. */
    public static function track(mixed $keyword): ?string
    {
        return is_string($keyword) ? TrackNumber::normalize($keyword) : null;
    }
}
