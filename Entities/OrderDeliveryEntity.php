<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Entities;

use Okay\Core\Entity\Entity;

/**
 * Що покупець обрав для замовлення, з копіями назв: пізніші зміни довідника не повинні переписувати історію.
 * Один рядок на замовлення (`order_id` унікальний); `id` потрібен базовому Entity для update().
 */
class OrderDeliveryEntity extends Entity
{
    protected static $fields = [
        'id',
        'order_id',
        'city_id',
        'city_name',
        'point_id',
        'point_title',
        'point_number',
        'point_kind',
        'prepay_only',
        'estimated_date',
        'price',
        'price_source',
        'cod',
        'cod_fee',
        'updated_at',
    ];

    protected static $defaultOrderFields = ['id DESC'];
    protected static $table = 'sviat__rozetka__order_delivery';
    protected static $tableAlias = 'rzod';
}
