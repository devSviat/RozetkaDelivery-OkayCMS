<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Extenders;

use Okay\Core\BackendTranslations;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrdersListFilter;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelStatus;

/** Ланки на DeliveryFilterHelper модуля Sviat/DeliveryFilter; Init реєструє їх лише тоді, коли хаб є серед файлів. */
class DeliveryFilterExtender implements ExtensionInterface
{
    public const string CARRIER = 'rozetka';

    public function __construct(
        private readonly ParcelRows $parcels,
        private readonly BackendTranslations $translations,
        private readonly Module $module,
        private readonly Modules $modules,
    ) {
    }

    /** ChainExtension для findDeliveryStatusFilters. */
    public function deliveryStatusFilters($filters)
    {
        if (!is_array($filters) || !$this->enabled()) {
            return $filters;
        }
        $options = [];
        foreach (ParcelStatus::FILTER_GROUPS as $group) {
            $options[$group] = $this->text('sviat__rozetka__parcel_group_' . $group);
        }
        $filters[self::CARRIER] = [
            'title' => $this->text('sviat__rozetka__delivery_filter_title'),
            'short' => $this->text('sviat__rozetka__delivery_filter_short'),
            'options' => $options,
        ];

        return $filters;
    }

    /** ChainExtension для filterByDeliveryStatus. */
    public function filterByDeliveryStatus($result, $filter = [], $carrier = '', $status = '')
    {
        if (!is_array($result) || $carrier !== self::CARRIER || !$this->enabled()) {
            return $result;
        }

        return OrdersListFilter::byStatus($result, $status, (int)$this->module->getModuleIdByNamespace(__CLASS__));
    }

    /** ChainExtension для findOrderIdsByShipmentNumber: точний збіг номера ЕН після нормалізації. */
    public function ordersByShipmentNumber($ids, $keyword = '')
    {
        $track = OrdersListFilter::track($keyword);
        if (!is_array($ids) || $track === null || !$this->enabled()) {
            return $ids;
        }
        $orderId = $this->parcels->orderOfTrack($track);
        if ($orderId !== null) {
            $ids[] = $orderId;
        }

        return $ids;
    }

    /** Адмінка запускає init() і вимкнених модулів, тож ланки вимкненого перевізника зареєстровані й мусять мовчати. */
    private function enabled(): bool
    {
        return (bool)$this->modules->isActiveModule('Sviat', 'RozetkaDelivery');
    }

    private function text(string $key): string
    {
        return (string)$this->translations->getTranslation($key);
    }
}
