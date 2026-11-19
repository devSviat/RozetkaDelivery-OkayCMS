<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows;

enum PointKind: string
{
    case Pickup = 'pickup';
    case Store = 'store';
    case Postomat = 'postomat';
    case Partner = 'partner';
    case Other = 'other';

    /** `department_type.id`: 2 точка видачі, 7 магазин, 10 поштомат, 14 партнерське відділення. */
    public static function fromTypeId(int $typeId): self
    {
        return match ($typeId) {
            2 => self::Pickup,
            7 => self::Store,
            10 => self::Postomat,
            14 => self::Partner,
            default => self::Other,
        };
    }
}
