<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/**
 * Стан рядка ЕН. `pending` — резерв до запиту, `uncertain` — запит пішов, а відповіді немає,
 * `created` — ЕН з `track_id`.
 */
final class PendingParcel
{
    public const string PENDING = 'pending';
    public const string UNCERTAIN = 'uncertain';
    public const string CREATED = 'created';

    /**
     * Створення — один запит до 20 с. Резерв, старший за 10 хвилин, лишився від процесу, що помер, і той міг
     * устигнути надіслати POST track: такий рядок невідомий, а не вільний, інакше повтор створив би другу ЕН.
     */
    public const int STALE_SECONDS = 600;

    /** Рядок без ЕН: резерв або невідомий результат. */
    public static function is(?object $row): bool
    {
        return $row !== null && ($row->state ?? null) !== self::CREATED;
    }

    /** Створення ще виконується в іншому запиті. */
    public static function running(?object $row, int $now): bool
    {
        return self::is($row) && !self::needsCheck($row, $now);
    }

    /** ЕН могла з'явитися в Rozetka: нова не створюється, доки цю не знайдено, не прив'язано чи не забуто менеджером. */
    public static function needsCheck(?object $row, int $now): bool
    {
        if (!self::is($row)) {
            return false;
        }
        if (($row->state ?? null) === self::UNCERTAIN) {
            return true;
        }
        $reservedAt = strtotime((string)($row->reserved_at ?? $row->created_at ?? ''));

        return $reservedAt === false || $now - $reservedAt > self::STALE_SECONDS;
    }
}
