<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Storage;

use Okay\Core\Entity\Entity;

class StateEntity extends Entity
{
    protected static $fields = [
        'id',
        'name',
        'value',
        'updated_at',
    ];

    protected static $table = 'sviat__rozetka__state';
    protected static $tableAlias = 'rzs';
    protected static $alternativeIdField = 'name';
}
