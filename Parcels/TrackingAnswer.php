<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/**
 * Відповідь `GET track/status?id[]=…`. Схема описує `data` як один об'єкт, хоча номерів у запиті кілька, тож
 * приймаються обидві форми.
 */
final class TrackingAnswer
{
    /** @return list<array{track_id: string, last: ?TrackingEvent, statuses: list<TrackingEvent>}> */
    public static function parse(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $items = array_is_list($data) ? $data : [$data];
        $parsed = [];
        foreach ($items as $item) {
            $trackId = is_array($item) ? ParcelService::track($item['track_id'] ?? null) : null;
            if ($trackId === null) {
                continue;
            }
            $statuses = array_values(array_filter(array_map(
                TrackingEvent::fromApi(...),
                is_array($item['statuses'] ?? null) && array_is_list($item['statuses']) ? $item['statuses'] : [],
            )));
            $last = TrackingEvent::fromApi($item['last_status'] ?? null);
            if ($last === null && $statuses !== []) {
                usort($statuses, static fn(TrackingEvent $a, TrackingEvent $b): int => $a->at <=> $b->at);
                $last = $statuses[array_key_last($statuses)];
            }
            $parsed[] = ['track_id' => $trackId, 'last' => $last, 'statuses' => $statuses];
        }

        return $parsed;
    }
}
