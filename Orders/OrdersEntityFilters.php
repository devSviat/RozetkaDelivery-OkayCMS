<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Orders;

use Okay\Core\Modules\AbstractModuleEntityFilter;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\ParcelEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelStatus;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PendingParcel;

/**
 * Фільтри OrdersEntity для списку замовлень. Прості підзапити IN: Aura бере пари alias.column у лапки
 * всередині EXISTS (…), і SQL ламається. Значення в SQL — лише числа й групи з ParcelStatus.
 */
class OrdersEntityFilters extends AbstractModuleEntityFilter
{
    /** @param int $moduleId замовлення з доставкою цього модуля без створеної ЕН */
    public function filter__rozetka_without_parcel($moduleId, $filter)
    {
        $moduleId = (int)$moduleId;
        $orders = OrdersEntity::getTableAlias();
        // Самовивіз і доставки без модуля мають module_id = 0: невідомий id модуля не повинен їх зачепити.
        if ($moduleId <= 0) {
            $this->select->where('1 = 0');

            return;
        }
        $deliveries = DeliveriesEntity::getTable();
        $parcels = ParcelEntity::getTable();
        // Резерв чи невідомий результат теж «без ЕН»: менеджер має їх побачити й розібрати.
        $this->select->where("{$orders}.delivery_id IN (SELECT id FROM {$deliveries} WHERE module_id = {$moduleId})")
            ->where("{$orders}.id NOT IN (SELECT order_id FROM {$parcels} WHERE state = '" . PendingParcel::CREATED . "')");
    }

    /** @param list<string> $groups замовлення, чия ЕН в одній із цих груп */
    public function filter__rozetka_status_groups($groups, $filter)
    {
        $known = array_diff(ParcelStatus::FILTER_GROUPS, [OrdersListFilter::NONE]);
        $groups = array_values(array_intersect($known, array_filter((array)$groups, is_string(...))));
        $list = $groups === [] ? "''" : implode(',', array_map(static fn(string $group): string => "'{$group}'", $groups));
        $orders = OrdersEntity::getTableAlias();
        $parcels = ParcelEntity::getTable();
        $this->select->where("{$orders}.id IN (SELECT order_id FROM {$parcels} WHERE status_group IN ({$list}))");
    }
}
