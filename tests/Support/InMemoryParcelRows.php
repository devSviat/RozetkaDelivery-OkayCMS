<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

use Okay\Modules\Sviat\RozetkaDelivery\Entities\ParcelEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelStatus;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PendingParcel;

/** Повторює унікальні order_id, track_id і visible_id таблиці: дубль не записується, як і в базі. */
final class InMemoryParcelRows implements ParcelRows
{
    private const array UNIQUE = ['order_id', 'track_id', 'visible_id'];

    /** @var array<int, object> за id рядка */
    public array $rows = [];
    /** База відхилила запис після того, як Rozetka створила ЕН. */
    public bool $failComplete = false;
    private int $nextId = 1;

    public function forOrder(int $orderId): ?object
    {
        foreach ($this->rows as $row) {
            if ($row->order_id === $orderId) {
                return $row;
            }
        }

        return null;
    }

    public function forOrders(array $orderIds): array
    {
        $found = [];
        foreach ($orderIds as $orderId) {
            $row = $this->forOrder($orderId);
            if ($row !== null) {
                $found[$orderId] = $row;
            }
        }

        return $found;
    }

    public function orderOfTrack(string $trackId): ?int
    {
        foreach ($this->rows as $row) {
            if ($row->track_id !== null && $row->track_id === $trackId) {
                return $row->order_id;
            }
        }

        return null;
    }

    public function reserve(int $orderId, string $now): ?string
    {
        $id = $this->insert(['order_id' => $orderId, 'state' => PendingParcel::PENDING, 'created_at' => $now, 'reserved_at' => $now]);
        if ($id === null) {
            return null;
        }
        $this->rows[$id]->visible_id = "{$orderId}-{$id}";

        return $this->rows[$id]->visible_id;
    }

    public function complete(int $orderId, array $values): bool
    {
        $row = $this->forOrder($orderId);
        if ($this->failComplete || !PendingParcel::is($row)) {
            return false;
        }

        return $this->write($row, $values + ['state' => PendingParcel::CREATED, 'reserved_at' => null, 'status_group' => 'created']);
    }

    public function attach(int $orderId, array $values): bool
    {
        $row = $this->forOrder($orderId);
        $values += ['state' => PendingParcel::CREATED, 'reserved_at' => null];
        if ($row === null) {
            return $this->insert($values + ['order_id' => $orderId]) !== null;
        }
        unset($values['created_at']);

        return PendingParcel::is($row) && $this->write($row, $values);
    }

    public function markUncertain(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if (PendingParcel::is($row)) {
            $row->state = PendingParcel::UNCERTAIN;
        }
    }

    public function release(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if (PendingParcel::is($row)) {
            unset($this->rows[$row->id]);
        }
    }

    public function remove(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if ($row !== null) {
            unset($this->rows[$row->id]);
        }
    }

    public function saveEvent(int $orderId, array $values): void
    {
        $row = $this->forOrder($orderId);
        if ($row !== null && !PendingParcel::is($row)) {
            $this->write($row, $values);
        }
    }

    public function unfinished(string $createdSince, int $limit): array
    {
        $due = array_filter($this->rows, static fn(object $row): bool => $row->state === PendingParcel::CREATED
            && $row->track_id !== null
            && $row->created_at >= $createdSince
            && !ParcelStatus::isFinal($row->status_group));
        usort($due, static fn(object $a, object $b): int => [$a->checked_at ?? '', $a->id] <=> [$b->checked_at ?? '', $b->id]);

        return array_slice(array_values($due), 0, $limit);
    }

    public function setReception(array $trackIds, ?int $receptionId): void
    {
        foreach ($this->rows as $row) {
            if (in_array($row->track_id, $trackIds, true)) {
                $row->reception_id = $receptionId;
            }
        }
    }

    public function clearReception(int $receptionId): void
    {
        foreach ($this->rows as $row) {
            if ((int)$row->reception_id === $receptionId) {
                $row->reception_id = null;
            }
        }
    }

    public function readyForReception(int $limit): array
    {
        $ready = array_filter($this->rows, static fn(object $row): bool => $row->state === PendingParcel::CREATED
            && $row->track_id !== null
            && in_array($row->status_group, [null, '', 'created'], true)
            && empty($row->reception_id));

        return array_slice(array_values($ready), 0, $limit);
    }

    /** Рядок ЕН у стані `created`, як після створення: для тестів трекінгу, видалення й друку. */
    public function created(int $orderId, string $trackId, array $values = []): object
    {
        $id = $this->insert($values + [
            'order_id' => $orderId,
            'track_id' => $trackId,
            'visible_id' => "{$orderId}-{$this->nextId}",
            'state' => PendingParcel::CREATED,
            'status_group' => 'created',
            'created_at' => '2027-01-15 10:00:00',
        ]);

        return $this->rows[(int)$id];
    }

    /** @param array<string, mixed> $values */
    private function insert(array $values): ?int
    {
        $row = (object)(['id' => $this->nextId] + array_fill_keys(ParcelEntity::getFields(), null));
        foreach (ParcelEntity::knownColumns($values) as $field => $value) {
            $row->{$field} = $value;
        }
        if ($this->duplicates($row)) {
            return null;
        }
        $this->rows[$this->nextId] = $row;

        return $this->nextId++;
    }

    /** @param array<string, mixed> $values */
    private function write(object $row, array $values): bool
    {
        $changed = clone $row;
        foreach (ParcelEntity::knownColumns($values) as $field => $value) {
            $changed->{$field} = $value;
        }
        if ($this->duplicates($changed)) {
            return false;
        }
        foreach ((array)$changed as $field => $value) {
            $row->{$field} = $value;
        }

        return true;
    }

    private function duplicates(object $candidate): bool
    {
        foreach ($this->rows as $row) {
            if ($row->id === $candidate->id) {
                continue;
            }
            foreach (self::UNIQUE as $column) {
                if ($candidate->{$column} !== null && $row->{$column} === $candidate->{$column}) {
                    return true;
                }
            }
        }

        return false;
    }
}
