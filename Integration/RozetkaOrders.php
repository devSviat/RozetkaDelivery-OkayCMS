<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Integration;

use Okay\Core\EntityFactory;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\ParcelEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelTracking;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PendingParcel;

/**
 * Контракт лише на читання для інших модулів. Усе поза Integration\ може змінитися без попередження;
 * цей клас і його value objects — ні.
 *
 * Використовуйте його лише тоді, коли модуль активний: class_exists(RozetkaOrders::class) і
 * Modules::isActiveModule('Sviat', 'RozetkaDelivery'); вимкнений модуль не реєструє сервісів.
 */
final class RozetkaOrders
{
    private const array PARCEL_COLUMNS = [
        'order_id', 'track_id', 'state', 'status_group', 'partial', 'cod_amount', 'has_paid', 'event_at', 'arrived_at', 'expiration_date',
    ];

    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly OrderDeliveryLines $deliveryLines,
        private readonly OrderDeliveryStore $snapshots,
    ) {
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, RozetkaParcel> за id замовлення; рядки без ЕН (резерв, невідомий результат) пропущено
     */
    public function parcels(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map(intval(...), $orderIds), static fn(int $id): bool => $id > 0)));
        if ($orderIds === []) {
            return [];
        }

        return $this->build($this->parcelEntity()->find(['order_id' => $orderIds]));
    }

    /** @return array<int, RozetkaParcel> ЕН, які трекінг ще опитує (не фінальні, створені не раніше ніж 60 днів тому) */
    public function unfinished(): array
    {
        $since = date('Y-m-d H:i:s', time() - ParcelTracking::TRACK_FOR_DAYS * 86400);

        return $this->build($this->parcelEntity()->find(['unfinished' => $since]));
    }

    /** Null, якщо доставка замовлення вже не цього модуля: знімок описує не її. */
    public function addressOf(int $orderId): ?RozetkaAddress
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->cols(['id', 'delivery_id'])->get($orderId);
        if (!$order || $this->deliveryLines->ownDelivery($order) === null) {
            return null;
        }

        return self::address($this->snapshots->forOrder($orderId));
    }

    /**
     * Замовлення з ЕН, видані повністю, з післяплатою, яку Rozetka підтвердила, з подією не раніше `$since`.
     * Частково видана посилка не входить: скільки грошей отримано, невідомо.
     *
     * @return list<int>
     */
    public function receivedCodSince(string $since): array
    {
        $rows = $this->entityFactory->get(ParcelEntity::class)->noLimit()->cols(['order_id'])->find(['received_cod_since' => $since]);

        return array_values(array_map(static fn(object $row): int => (int)$row->order_id, $rows));
    }

    /** Повний підзапит, а не назва таблиці: споживачі не мають знати її колонок. */
    public static function withTrackSql(): string
    {
        return "SELECT order_id FROM " . ParcelEntity::getTable()
            . " WHERE state = '" . PendingParcel::CREATED . "' AND track_id IS NOT NULL AND track_id <> ''";
    }

    public static function parcel(object $row): ?RozetkaParcel
    {
        $trackId = trim((string)($row->track_id ?? ''));
        if (($row->state ?? null) !== PendingParcel::CREATED || $trackId === '') {
            return null;
        }
        $group = ($row->status_group ?? null) !== null && $row->status_group !== '' ? (string)$row->status_group : null;
        $hasPaid = ($row->has_paid ?? null) === null ? null : (int)$row->has_paid === 1;

        return new RozetkaParcel(
            (int)$row->order_id,
            $trackId,
            $group,
            (int)($row->partial ?? 0) === 1,
            (float)($row->cod_amount ?? 0) > 0,
            $hasPaid,
            self::date($row->event_at ?? null),
            $group === 'arrived' ? self::date($row->arrived_at ?? null) : null,
            self::date($row->expiration_date ?? null),
        );
    }

    public static function address(?object $snapshot): ?RozetkaAddress
    {
        if ($snapshot === null) {
            return null;
        }
        $text = static fn(string $name): string => trim((string)($snapshot->$name ?? ''));
        // city_name містить через кому район і область; адресі потрібні лише тип і назва.
        $city = trim(explode(', ', $text('city_name'))[0]);

        return new RozetkaAddress($city, $text('point_title'), $text('point_number'));
    }

    private function parcelEntity(): ParcelEntity
    {
        return $this->entityFactory->get(ParcelEntity::class)->noLimit()->cols(self::PARCEL_COLUMNS);
    }

    /**
     * @param iterable<object> $rows
     * @return array<int, RozetkaParcel>
     */
    private function build(iterable $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $parcel = self::parcel($row);
            if ($parcel !== null) {
                $result[$parcel->orderId] = $parcel;
            }
        }

        return $result;
    }

    private static function date(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' || str_starts_with($value, '0000') ? null : $value;
    }
}
