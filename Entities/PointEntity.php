<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Entities;

use Okay\Core\Entity\Entity;

/** Точки ROZETKA Delivery; `id` — UUID відділення в нижньому регістрі, його приймає `POST track`. */
class PointEntity extends Entity
{
    protected static $fields = [
        'id',
        'city_id',
        'number',
        'kind',
        'type_id',
        'title',
        'public_name',
        'address',
        'lat',
        'lng',
        'schedule',
        'extra_schedule',
        'max_weight',
        'max_volume_weight',
        'max_length',
        'max_cost',
        'can_receive',
        'can_give_out',
        'prepay_only',
        'row_hash',
        'seen_run',
        'updated_at',
    ];

    protected static $defaultOrderFields = ['number', 'id'];
    protected static $table = 'sviat__rozetka__points';
    protected static $tableAlias = 'rzp';
}
