<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/** Що масовому створенню треба знати про замовлення. */
interface MassOrders
{
    /** Замовлення існує й доставляється цим модулем. */
    public function isOurs(int $orderId): bool;

    /** @return ParcelDraft|string масова чернетка (без `release_unpaid`) або мовний ключ того, чого бракує замовленню */
    public function draft(int $orderId): ParcelDraft|string;
}
