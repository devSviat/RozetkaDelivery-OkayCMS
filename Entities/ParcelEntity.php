<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Entities;

use Okay\Core\Entity\Entity;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelStatus;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PendingParcel;

/**
 * ЕН ROZETKA Delivery замовлення, одна на замовлення (`order_id`, `track_id`, `visible_id` унікальні).
 * Рядок з'являється до запиту до Rozetka як резерв: див. PendingParcel.
 */
class ParcelEntity extends Entity
{
    protected static $fields = [
        'id',
        'order_id',
        'track_id',
        'visible_id',
        'state',
        'status_code',
        'status_raw',
        'status_group',
        'partial',
        'event_name',
        'event_at',
        'arrived_at',
        'expiration_date',
        'delivery_date',
        'shipping_cost',
        'payment_fee',
        'cod_amount',
        'declared_value',
        'has_paid',
        'delivery_payer',
        'payer_reason',
        'point_id',
        'reception_id',
        'details',
        'history',
        'created_at',
        'reserved_at',
        'checked_at',
    ];

    /**
     * Лише колонки, які entity знає: ядро пише в INSERT/UPDATE усі ключі, і невідома колонка провалила б увесь запис.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function knownColumns(array $values): array
    {
        return array_intersect_key($values, array_flip(static::getAllDefaultFields()));
    }

    /** Створені ЕН з номером без фінальної групи, створені не раніше `since`. */
    protected function filter__unfinished(string $since): void
    {
        $alias = $this->getTableAlias();
        $final = implode(',', array_map(static fn(string $g): string => "'{$g}'", ParcelStatus::FINAL));
        $this->select->where("{$alias}.state = '" . PendingParcel::CREATED . "'")
            ->where("{$alias}.track_id IS NOT NULL")
            ->where("{$alias}.created_at >= :rozetka_unfinished_since")
            // Явний IS NULL: NOT IN із NULL дало б NULL, і рядок без групи випав би з вибірки.
            ->where("({$alias}.status_group IS NULL OR {$alias}.status_group NOT IN ({$final}))")
            ->bindValue('rozetka_unfinished_since', $since);
    }

    /** Повністю видані ЕН з післяплатою, оплату якої Rozetka підтвердила, з подією не раніше `since`. */
    protected function filter__received_cod_since(string $since): void
    {
        $alias = $this->getTableAlias();
        $this->select->where("{$alias}.state = '" . PendingParcel::CREATED . "'")
            ->where("{$alias}.status_group = 'received'")
            ->where("{$alias}.partial = 0")
            ->where("{$alias}.cod_amount > 0")
            ->where("{$alias}.has_paid = 1")
            ->where("{$alias}.event_at >= :rozetka_received_since")
            ->bindValue('rozetka_received_since', $since);
    }

    /**
     * Повністю видані ЕН з післяплатою без підтвердженої оплати, з подією не раніше `event_since` і не перевірені
     * після `checked_before`.
     *
     * @param array{event_since: string, checked_before: string} $range
     */
    protected function filter__unpaid_cod(array $range): void
    {
        $alias = $this->getTableAlias();
        $this->select->where("{$alias}.state = '" . PendingParcel::CREATED . "'")
            ->where("{$alias}.track_id IS NOT NULL")
            ->where("{$alias}.status_group = 'received'")
            ->where("{$alias}.partial = 0")
            ->where("{$alias}.cod_amount > 0")
            // Явний IS NULL: NULL <> 1 дало б NULL, і ЕН без відповіді про оплату випала б із вибірки.
            ->where("({$alias}.has_paid IS NULL OR {$alias}.has_paid <> 1)")
            ->where("{$alias}.event_at >= :rozetka_unpaid_event_since")
            ->where("({$alias}.checked_at IS NULL OR {$alias}.checked_at <= :rozetka_unpaid_checked_before)")
            ->bindValue('rozetka_unpaid_event_since', $range['event_since'])
            ->bindValue('rozetka_unpaid_checked_before', $range['checked_before']);
    }

    /** Створені ЕН у групі `created` (чи ще без групи) без реєстру. */
    protected function filter__ready_for_reception(bool $on): void
    {
        $alias = $this->getTableAlias();
        $this->select->where("{$alias}.state = '" . PendingParcel::CREATED . "'")
            ->where("{$alias}.track_id IS NOT NULL")
            ->where("({$alias}.status_group IS NULL OR {$alias}.status_group IN ('', 'created'))")
            ->where("{$alias}.reception_id IS NULL");
    }

    /** `stale`: спершу ті, що найдовше не перевірялися (NULL — першими); `oldest`: за датою створення. */
    protected function customOrder($order = null, array $orderFields = [], array $additionalData = [])
    {
        if ($order === 'stale') {
            $orderFields = ['checked_at', 'id'];
        } elseif ($order === 'oldest') {
            $orderFields = ['created_at', 'id'];
        }

        return parent::customOrder($order, $orderFields, $additionalData);
    }

    protected static $defaultOrderFields = ['id DESC'];
    protected static $table = 'sviat__rozetka__parcels';
    protected static $tableAlias = 'rzprc';
}
