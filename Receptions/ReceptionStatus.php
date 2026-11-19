<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Receptions;

/** Статуси реєстру з `GET reception-status`: `new` сформований, `printed`, `accepting`, `part_accepted`, `accepted` закритий. */
final class ReceptionStatus
{
    public const array KNOWN = ['new', 'printed', 'accepting', 'part_accepted', 'accepted'];
    /** Реєстр ще в магазині: з нього можна прибрати ЕН чи видалити його. */
    public const array OPEN = ['new', 'printed'];
    public const string FINAL = 'accepted';

    /** `status{id, name, color}` з відповіді або рядок; невідоме — null. */
    public static function of(mixed $status): ?string
    {
        $id = is_array($status) ? ($status['id'] ?? null) : $status;

        return is_string($id) && in_array($id, self::KNOWN, true) ? $id : null;
    }

    public static function isOpen(?string $status): bool
    {
        return in_array($status, self::OPEN, true);
    }

    public static function isFinal(?string $status): bool
    {
        return $status === self::FINAL;
    }
}
