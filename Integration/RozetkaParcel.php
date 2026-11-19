<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Integration;

/**
 * `trackId` — номер ЕН, який бачить одержувач. `group` — група статусів модуля (created, accepted, in_transit,
 * arrived, refused, returning, received, returned, cancelled, problem). `partial` — частково видана.
 * `cod` — одержувач платить при отриманні; `hasPaid` — Rozetka підтвердила оплату (null — ще невідомо).
 * `arrivedAt` — лише поки посилка чекає в точці. Час — у поясі застосунку.
 */
final readonly class RozetkaParcel
{
    public function __construct(
        public int $orderId,
        public string $trackId,
        public ?string $group,
        public bool $partial,
        public bool $cod,
        public ?bool $hasPaid,
        public ?string $eventAt,
        public ?string $arrivedAt,
        public ?string $expirationDate,
    ) {
    }
}
