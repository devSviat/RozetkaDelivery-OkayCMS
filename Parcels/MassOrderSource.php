<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Core\EntityFactory;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryLines;

final class MassOrderSource implements MassOrders
{
    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly OrderDeliveryLines $lines,
        private readonly ParcelDrafts $drafts,
    ) {
    }

    public function isOurs(int $orderId): bool
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);

        return is_object($order) && $this->lines->ownDelivery($order) !== null;
    }

    public function draft(int $orderId): ParcelDraft|string
    {
        return $this->drafts->forOrder($orderId, true);
    }
}
