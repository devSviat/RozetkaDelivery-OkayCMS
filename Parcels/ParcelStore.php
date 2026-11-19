<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\ParcelEntity;

final class ParcelStore implements ParcelRows
{
    /** Що читає рядок ЕН у списку замовлень: без JSON знімка й історії. */
    public const array LIST_COLUMNS = ['order_id', 'track_id', 'state', 'status_code', 'status_group', 'partial', 'event_name', 'reception_id'];

    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    /** @phpstan-impure кожен виклик перечитує рядок: перевірка запису порівнює стан до й після */
    public function forOrder(int $orderId): ?object
    {
        $row = $this->entity()->findOne(['order_id' => $orderId]);

        return is_object($row) ? $row : null;
    }

    public function forOrders(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map(intval(...), $orderIds), static fn(int $id): bool => $id > 0)));
        if ($orderIds === []) {
            return [];
        }
        $rows = [];
        foreach ($this->entity()->cols(self::LIST_COLUMNS)->find(['order_id' => $orderIds, 'limit' => count($orderIds)]) as $row) {
            $rows[(int)$row->order_id] = $row;
        }

        return $rows;
    }

    public function orderOfTrack(string $trackId): ?int
    {
        $row = $this->entity()->findOne(['track_id' => $trackId]);

        return is_object($row) ? (int)$row->order_id : null;
    }

    public function reserve(int $orderId, string $now): ?string
    {
        // Ядро ковтає помилку дубліката ключа: другий запит дізнається, що замовлення зайняте, з false.
        $id = $this->entity()->add([
            'order_id' => $orderId,
            'state' => PendingParcel::PENDING,
            'created_at' => $now,
            'reserved_at' => $now,
        ]);
        // На стоковому ядрі невдала вставка може віддати id попередньої вставки з'єднання: вірить лише перечитаний рядок.
        $row = $id ? $this->forOrder($orderId) : null;
        if ($row === null || (int)$row->id !== (int)$id || $row->visible_id !== null || !PendingParcel::is($row)) {
            return null;
        }
        // id відомий лише після вставки, тож номер спроби пишеться другим кроком; NULL у UNIQUE колонці дублем не є.
        $visibleId = "{$orderId}-{$row->id}";
        $this->entity()->update((int)$row->id, ['visible_id' => $visibleId]);
        // Невдалий UPDATE ядро теж ковтає. Запит до Rozetka ще не йшов, тож резерв без номера безпечно знімається.
        $numbered = $this->forOrder($orderId);
        if ($numbered === null || $numbered->visible_id !== $visibleId) {
            $this->entity()->delete([(int)$row->id]);

            return null;
        }

        return $visibleId;
    }

    public function complete(int $orderId, array $values): bool
    {
        $row = $this->forOrder($orderId);
        if (!PendingParcel::is($row)) {
            return false;
        }
        $values = ['state' => PendingParcel::CREATED, 'reserved_at' => null] + $values + ['status_group' => 'created'];

        return $this->updateChecked((int)$row->id, $orderId, $values);
    }

    public function attach(int $orderId, array $values): bool
    {
        $row = $this->forOrder($orderId);
        $values = ['state' => PendingParcel::CREATED, 'reserved_at' => null] + $values;
        if ($row === null) {
            $id = $this->entity()->add(ParcelEntity::knownColumns($values + ['order_id' => $orderId]));
            // Як і в reserve(): успіх видно лише з перечитаного рядка замовлення.
            $saved = $id ? $this->forOrder($orderId) : null;

            return $saved !== null
                && $saved->state === PendingParcel::CREATED
                && $saved->track_id === ($values['track_id'] ?? null);
        }
        if (!PendingParcel::is($row)) {
            return false;
        }
        // Невідомий рядок стає ЕН, а дата створення лишається датою резерву.
        unset($values['created_at']);

        return $this->updateChecked((int)$row->id, $orderId, $values);
    }

    public function markUncertain(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if (PendingParcel::is($row)) {
            $this->entity()->update((int)$row->id, ['state' => PendingParcel::UNCERTAIN]);
        }
    }

    public function release(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if (PendingParcel::is($row)) {
            $this->entity()->delete([(int)$row->id]);
        }
    }

    public function remove(int $orderId): void
    {
        $row = $this->forOrder($orderId);
        if ($row !== null) {
            $this->entity()->delete([(int)$row->id]);
        }
    }

    public function saveEvent(int $orderId, array $values): void
    {
        $row = $this->forOrder($orderId);
        if ($row !== null && !PendingParcel::is($row)) {
            $this->entity()->update((int)$row->id, ParcelEntity::knownColumns($values));
        }
    }

    public function unfinished(string $createdSince, int $limit): array
    {
        return array_values($this->entity()->order('stale')->find(['unfinished' => $createdSince, 'limit' => $limit]));
    }

    public function setReception(array $trackIds, ?int $receptionId): void
    {
        $trackIds = array_values(array_filter($trackIds, is_string(...)));
        if ($trackIds === []) {
            return;
        }
        foreach ($this->entity()->cols(['id'])->find(['track_id' => $trackIds, 'limit' => count($trackIds)]) as $row) {
            $this->entity()->update((int)$row->id, ['reception_id' => $receptionId]);
        }
    }

    public function clearReception(int $receptionId): void
    {
        foreach ($this->entity()->cols(['id'])->noLimit()->find(['reception_id' => $receptionId]) as $row) {
            $this->entity()->update((int)$row->id, ['reception_id' => null]);
        }
    }

    public function readyForReception(int $limit): array
    {
        return array_values($this->entity()->order('oldest')->find(['ready_for_reception' => true, 'limit' => $limit]));
    }

    /**
     * Дубль унікального ключа update() теж ковтає: успіх видно лише з перечитаного рядка.
     *
     * @param array<string, mixed> $values
     */
    private function updateChecked(int $id, int $orderId, array $values): bool
    {
        $values = ParcelEntity::knownColumns($values);
        $this->entity()->update($id, $values);
        $saved = $this->forOrder($orderId);

        return $saved !== null
            && $saved->state === PendingParcel::CREATED
            && ($values['track_id'] ?? null) === $saved->track_id;
    }

    private function entity(): ParcelEntity
    {
        return $this->entityFactory->get(ParcelEntity::class);
    }
}
