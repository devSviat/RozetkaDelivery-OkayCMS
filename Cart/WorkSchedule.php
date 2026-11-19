<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Cart;

/**
 * Тиждень точки так, як його показує кошик: дні з однаковими годинами в одній групі ("Пн–Пт 08:30–18:00"),
 * і окремі дні зі зміненими годинами (`extra_schedule`), які ще не минули.
 */
final class WorkSchedule
{
    private const int MAX_SPECIAL = 5;
    private const array DAYS = [
        'пн' => 0, 'вт' => 1, 'ср' => 2, 'чт' => 3, 'пт' => 4, 'сб' => 5, 'нд' => 6,
        'mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6,
    ];
    private const string INTERVAL = '/([01]?\d|2[0-4]):([0-5]\d)(?::[0-5]\d)?\s*[-–—]\s*([01]?\d|2[0-4]):([0-5]\d)(?::[0-5]\d)?/u';

    /**
     * @param ?string $schedule колонка `schedule`: JSON рядків "Пн: 08:30-18:00"
     * @param ?string $extra колонка `extra_schedule`: JSON [{start:{date,time}, end:{date,time}}]
     * @param string $today Y-m-d за Києвом
     */
    public static function fromColumns(?string $schedule, ?string $extra, string $today): array
    {
        $lines = json_decode((string)$schedule, true);
        $special = json_decode((string)$extra, true);

        return self::parse(is_array($lines) ? $lines : [], is_array($special) ? $special : null, $today);
    }

    /**
     * Перший інтервал рядка — години, другий (якщо є) — перерва. Рядок без інтервалу — вихідний.
     *
     * @param list<mixed> $lines
     * @param ?list<mixed> $extra
     * @return array{week: list<array{from:int, to:int, hours:?string, break:?string}>|list<array{allDay:true}>|null, special: list<array{date:string, hours:?string}>}
     *         дні 0 = понеділок … 6 = неділя; week null — розкладу немає або його не вдається прочитати
     */
    public static function parse(array $lines, ?array $extra, string $today): array
    {
        return ['week' => self::week($lines), 'special' => self::special($extra ?? [], $today)];
    }

    /** @param list<mixed> $lines */
    private static function week(array $lines): ?array
    {
        $days = array_fill(0, 7, null);
        $known = array_fill(0, 7, false);
        foreach ($lines as $line) {
            if (!is_string($line) || preg_match('/^\s*([^\s:]+)\s*:\s*(.*)$/u', $line, $m) !== 1) {
                continue;
            }
            $day = self::DAYS[mb_strtolower(mb_substr($m[1], 0, 3))] ?? self::DAYS[mb_strtolower(mb_substr($m[1], 0, 2))] ?? null;
            if ($day === null) {
                continue;
            }
            $known[$day] = true;
            preg_match_all(self::INTERVAL, $m[2], $intervals, PREG_SET_ORDER);
            $days[$day] = $intervals === [] ? null : [
                'hours' => self::interval($intervals[0]),
                'break' => isset($intervals[1]) ? self::interval($intervals[1]) : null,
            ];
        }
        if (!in_array(true, $known, true)) {
            return null;
        }
        $allDay = array_filter($days, static fn(?array $d): bool => $d !== null && $d['hours'] === '00:00–23:59' && $d['break'] === null);
        if (count($allDay) === 7) {
            return [['allDay' => true]];
        }

        $groups = [];
        foreach ($days as $day => $hours) {
            $text = $hours['hours'] ?? null;
            $break = $hours['break'] ?? null;
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['hours'] === $text && $groups[$last]['break'] === $break) {
                $groups[$last]['to'] = $day;
            } else {
                $groups[] = ['from' => $day, 'to' => $day, 'hours' => $text, 'break' => $break];
            }
        }

        return $groups;
    }

    /**
     * @param list<mixed> $extra
     * @return list<array{date:string, hours:?string}>
     */
    private static function special(array $extra, string $today): array
    {
        $days = [];
        foreach ($extra as $item) {
            $date = is_array($item) && is_array($item['start'] ?? null) ? ($item['start']['date'] ?? null) : null;
            if (!is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date < $today) {
                continue;
            }
            $from = self::time($item['start']['time'] ?? null);
            $to = is_array($item['end'] ?? null) ? self::time($item['end']['time'] ?? null) : null;
            $days[] = ['date' => $date, 'hours' => $from !== null && $to !== null ? $from . '–' . $to : null];
        }
        usort($days, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));

        return array_slice($days, 0, self::MAX_SPECIAL);
    }

    /** @param array<int, string> $m збіг INTERVAL */
    private static function interval(array $m): string
    {
        return sprintf('%02d:%s–%02d:%s', (int)$m[1], $m[2], (int)$m[3], $m[4]);
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]?\d|2[0-4]):([0-5]\d)(?::[0-5]\d)?$/', trim($value), $m) === 1 ? sprintf('%02d:%s', (int)$m[1], $m[2]) : null;
    }
}
