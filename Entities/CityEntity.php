<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Entities;

use Okay\Core\Entity\Entity;

/** Населені пункти з точками ROZETKA Delivery; `id` — `location.city_id` (UUID у нижньому регістрі). */
class CityEntity extends Entity
{
    protected static $fields = [
        'id',
        'region_id',
        'district_id',
        'type',
        'name_ua',
        'district_ua',
        'region_ua',
        'search_text',
        'points',
        'row_hash',
        'seen_run',
        'updated_at',
    ];

    // Підказка міста показує перші записи, тож міста з більшою кількістю точок ідуть першими.
    protected static $defaultOrderFields = ['points DESC', 'name_ua', 'id'];
    protected static $searchFields = ['search_text'];
    protected static $table = 'sviat__rozetka__cities';
    protected static $tableAlias = 'rzc';
}
