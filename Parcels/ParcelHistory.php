<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/**
 * Історія статусів ЕН у колонці `history`: JSON у хронологічному порядку, лише найновіші MAX_EVENTS.
 * Читання перевіряє кожен запис: зіпсований JSON дає порожню історію, а не помилку картки.
 *
 * @phpstan-type Entry array{code: ?int, raw: string, name: string, at: string}
 */
final class ParcelHistory
{
    public const int MAX_EVENTS = 50;

    /**
     * @param list<TrackingEvent> $events
     * @return list<Entry>
     */
    public static function fromEvents(array $events): array
    {
        $history = [];
        foreach ($events as $event) {
            $history = self::append($history, $event);
        }

        return $history;
    }

    /**
     * Той самий статус приходить у кожній відповіді: ключ — статус і час.
     *
     * @param list<Entry> $history
     * @return list<Entry>
     */
    public static function append(array $history, TrackingEvent $event): array
    {
        $entry = self::entry($event);
        foreach ($history as $known) {
            if ($known['raw'] === $entry['raw'] && $known['at'] === $entry['at']) {
                return $history;
            }
        }
        $history[] = $entry;
        usort($history, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);

        return array_slice($history, -self::MAX_EVENTS);
    }

    /** @param list<Entry> $history */
    public static function encode(array $history): ?string
    {
        if ($history === []) {
            return null;
        }
        $json = json_encode(array_slice($history, -self::MAX_EVENTS), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }

    /** @return list<Entry> */
    public static function decode(mixed $json): array
    {
        $items = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($items) || !array_is_list($items)) {
            return [];
        }
        $history = [];
        foreach ($items as $item) {
            if (!is_array($item)
                || !array_key_exists('code', $item) || !(is_int($item['code']) || $item['code'] === null)
                || !is_string($item['raw'] ?? null)
                || !is_string($item['name'] ?? null)
                || !is_string($item['at'] ?? null)
                || \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $item['at']) === false) {
                continue;
            }
            $history[] = [
                'code' => $item['code'],
                'raw' => mb_substr($item['raw'], 0, 64),
                'name' => mb_substr($item['name'], 0, TrackingEvent::TEXT_LENGTH),
                'at' => $item['at'],
            ];
        }

        return array_slice($history, -self::MAX_EVENTS);
    }

    /** @return Entry */
    private static function entry(TrackingEvent $event): array
    {
        return ['code' => $event->code, 'raw' => $event->raw, 'name' => $event->name, 'at' => $event->at];
    }
}
