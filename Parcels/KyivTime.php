<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/** Дати Rozetka приходять без поясу й читаються як київські (спека §14); база тримає час застосунку. */
final class KyivTime
{
    public const string ZONE = 'Europe/Kyiv';

    /** `Y-m-d H:i:s` у поясі застосунку або null, коли це не дата. */
    public static function toLocal(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $value, $m) !== 1) {
            return null;
        }
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        $kyiv = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00', ($m[6] ?? '') === '' ? '00' : $m[6]);

        return new \DateTimeImmutable($kyiv, new \DateTimeZone(self::ZONE))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }

    /** Київська дата моменту `$at` (Y-m-d): фільтри `created_date_*` API — у київських днях. */
    public static function day(int $at): string
    {
        return new \DateTimeImmutable('@' . $at)->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d');
    }
}
