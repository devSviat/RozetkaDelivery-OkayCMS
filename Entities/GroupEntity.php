<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Entities;

use Okay\Core\Entity\Entity;

/** Вкладка точок у кошику (напр. «Поштомати»); `kinds` — JSON-список PointKind. */
class GroupEntity extends Entity
{
    protected static $fields = [
        'id',
        'position',
        'enabled',
        'kinds',
    ];

    protected static $langFields = [
        'name',
    ];

    protected static $defaultOrderFields = ['position', 'id'];
    protected static $table = 'sviat__rozetka__groups';
    protected static $langObject = 'group';
    protected static $langTable = 'sviat__rozetka__groups';
    protected static $tableAlias = 'rzg';
}
