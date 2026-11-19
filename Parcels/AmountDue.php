<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Core\Modules\Extender\ExtenderFacade;

/** Що точка ROZETKA стягує при отриманні; модулі передоплати зменшують це через ланцюжок. */
final class AmountDue
{
    public function onPickup(object $order, float $totalUah): float
    {
        return ExtenderFacade::execute(__METHOD__, max(0.0, $totalUah), func_get_args());
    }
}
