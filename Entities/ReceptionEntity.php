<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Entities;

use Okay\Core\Entity\Entity;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionStatus;

/** Реєстр передачі ROZETKA Delivery, сформований з магазину (`reception_id` — номер Rozetka, унікальний). */
class ReceptionEntity extends Entity
{
    protected static $fields = [
        'id',
        'reception_id',
        'status',
        'department_id',
        'tracks_amount',
        'created_at',
        'checked_at',
    ];

    protected function filter__created_since(string $since): void
    {
        $this->select->where($this->getTableAlias() . '.created_at >= :rozetka_reception_since')
            ->bindValue('rozetka_reception_since', $since);
    }

    /** Явний IS NULL: `<>` з NULL дав би NULL, і реєстр без статусу випав би з вибірки. */
    protected function filter__not_final(bool $on): void
    {
        $alias = $this->getTableAlias();
        $this->select->where("({$alias}.status IS NULL OR {$alias}.status <> '" . ReceptionStatus::FINAL . "')");
    }

    /** `stale`: спершу ті, що найдовше не перевірялися. */
    protected function customOrder($order = null, array $orderFields = [], array $additionalData = [])
    {
        if ($order === 'stale') {
            $orderFields = ['checked_at', 'id'];
        }

        return parent::customOrder($order, $orderFields, $additionalData);
    }

    protected static $defaultOrderFields = ['id DESC'];
    protected static $table = 'sviat__rozetka__receptions';
    protected static $tableAlias = 'rzrcp';
}
