<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionRows;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionStatus;

/** Реєстри в пам'яті, за номером реєстру Rozetka. */
final class InMemoryReceptions implements ReceptionRows
{
    /** @var array<int, object> */
    public array $rows = [];

    public function get(int $receptionId): ?object
    {
        return $this->rows[$receptionId] ?? null;
    }

    public function save(int $receptionId, array $values): void
    {
        $this->rows[$receptionId] = (object)($values + (array)($this->rows[$receptionId] ?? []) + [
            'reception_id' => $receptionId, 'status' => null, 'department_id' => null, 'tracks_amount' => 0, 'created_at' => null, 'checked_at' => null,
        ]);
    }

    public function remove(int $receptionId): void
    {
        unset($this->rows[$receptionId]);
    }

    public function recent(string $since): array
    {
        return array_values(array_filter($this->rows, static fn(object $row): bool => $row->created_at >= $since));
    }

    public function open(string $since, int $limit): array
    {
        $open = array_filter($this->rows, static fn(object $row): bool => $row->created_at >= $since && !ReceptionStatus::isFinal($row->status));
        usort($open, static fn(object $a, object $b): int => [$a->checked_at ?? '', $a->reception_id] <=> [$b->checked_at ?? '', $b->reception_id]);

        return array_slice(array_values($open), 0, $limit);
    }
}
