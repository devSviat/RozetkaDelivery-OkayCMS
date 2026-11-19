<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Extenders;

use Okay\Core\BackendTranslations;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Entities\CurrenciesEntity;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\PaymentsEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\DeliveryWarnings;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderPointChange;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrdersMassAction;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderRowsCleanup;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelFile;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelCard;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelView;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PendingParcel;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPaymentInput;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;

class BackendExtender implements ExtensionInterface
{
    /** Тексти, які показує скрипт редактора точки; ключі Backend/lang без префікса. */
    private const array EDITOR_TEXTS = [
        'order_city', 'order_point', 'order_estimated', 'order_cod', 'order_cod_fee', 'order_cod_yes', 'order_payer', 'order_payer_receiver',
        'op_city_placeholder', 'op_point_search', 'op_point_search_placeholder',
        'op_save', 'op_cancel', 'op_saving', 'op_change', 'op_no_points', 'op_choose_point', 'op_network',
        'op_unfit_too_heavy', 'op_unfit_too_long', 'op_unfit_too_bulky', 'op_unfit_over_cost', 'op_unfit_prepay_only', 'op_unfit_cod_over_limit',
        'op_kind_pickup', 'op_kind_store', 'op_kind_postomat', 'op_kind_partner', 'op_kind_other',
        'op_price_keep', 'op_price_same', 'op_price_free', 'op_price_show', 'op_price_offer', 'op_price_apply', 'op_price_applied',
    ];

    public function __construct(
        private readonly Design $design,
        private readonly Request $request,
        private readonly Module $module,
        private readonly EntityFactory $entityFactory,
        private readonly CodSettings $codSettings,
        private readonly BackendTranslations $translations,
        private readonly OrderDeliveryStore $orders,
        private readonly OrderRowsCleanup $orderRows,
        private readonly OrderDeliveryLines $orderLines,
        private readonly ParcelRows $parcels,
        private readonly ParcelView $parcelView,
        private readonly Modules $modules,
        private readonly Response $response,
        private readonly OrdersMassAction $massAction,
    ) {
    }

    /** ChainExtension для BackendOrdersHelper::buildFilter: дія списку (ядро виконує свої дії безпосередньо перед цим). */
    public function listFilter($filter)
    {
        if (is_array($filter) && $this->enabled()) {
            $this->handleMassAction();
        }

        return $filter;
    }

    private function handleMassAction(): void
    {
        if ($this->request->method('post')) {
            $result = $this->massAction->handleRequest(fn(): bool => (bool)$this->request->checkSession(), $_SESSION);
            if ($result instanceof LabelFile) {
                $this->sendFile($result);
            }
            if ($result === true) {
                Response::redirectTo($this->request->url());
            }

            return;
        }
        $taken = OrdersMassAction::take($_SESSION);
        $this->design->assign('rozetka_mass_result', $taken === null ? null : OrdersMassAction::view(
            $taken,
            fn(string $key): string => (string)$this->translations->getTranslation($key),
        ));
    }

    /** Хук фільтра виконується посеред контролера списку: файл іде одразу, решта сторінки не рендериться. */
    private function sendFile(LabelFile $file): never
    {
        $this->response->setStatusCode(200)
            ->addHeader('Content-Type: ' . $file->mime)
            ->addHeader('Content-Disposition: attachment; filename="' . $file->filename . '"')
            ->addHeader('Cache-Control: no-store')
            ->addHeader('X-Content-Type-Options: nosniff')
            ->setContent($file->bytes, RESPONSE_TEXT)
            ->sendContent();
        exit;
    }

    /** QueueExtension для BackendOrdersHelper::findOrders: бейджі сторінки з бази одним запитом, без Rozetka. */
    public function listOrders($orders, $filter = [])
    {
        if (!is_array($orders) || $orders === [] || !$this->enabled()) {
            return;
        }
        $rows = $this->parcels->forOrders(array_map(static fn($order): int => (int)$order->id, array_values($orders)));
        foreach ($orders as $order) {
            $row = $rows[(int)$order->id] ?? null;
            if ($row !== null) {
                $order->rozetka_list_parcel = self::listParcel($row);
            }
        }
    }

    /** @return array{track: ?string, group: ?string, statusKey: ?string, eventName: ?string} */
    public static function listParcel(object $row): array
    {
        // Резерв чи невідомий результат: номера ще немає, але менеджер має побачити, що замовлення зайняте.
        if (PendingParcel::is($row)) {
            return ['track' => null, 'group' => 'unsettled', 'statusKey' => 'sviat__rozetka__list_unsettled', 'eventName' => null];
        }
        $card = ParcelCard::fromRow($row, '');

        return [
            'track' => $card['trackId'],
            'group' => $card['statusGroup'],
            'statusKey' => $card['statusKey'],
            'eventName' => $card['event']['name'] ?? null,
        ];
    }

    /** QueueExtension для BackendOrdersHelper::delete (виконується до того, як ядро видалить замовлення). */
    public function deleteOrders($result, $ids): void
    {
        $this->orderRows->forOrders((array)$ids);
    }

    /** QueueExtension для BackendOrdersHelper::findOrder: сторінка замовлення показує й змінює точку та ЕН. Лише читає. */
    public function assignOrderDelivery($order, $orderId = null)
    {
        $found = is_object($order) && !empty($order->id);
        $own = $found && $this->orderLines->ownDelivery($order) !== null;
        $this->design->assign('rozetka_order_delivery', $own ? $this->orderLines->forOrder($order) : null);
        $this->design->assign('rozetka_order_point', $own ? $this->pointEditor($order) : null);
        $this->design->assign('rozetka_parcel', $found && $this->enabled() ? $this->parcelView->forOrder($order) : null);
    }

    /** Адмінка запускає init() і вимкнених модулів: ланки зареєстровані, тож вимкнений модуль мусить мовчати сам. */
    private function enabled(): bool
    {
        return (bool)$this->modules->isActiveModule('Sviat', 'RozetkaDelivery');
    }

    /** Те, що потрібно редактору точки; збережена точка йде лише з UUID, решту редактор питає сам. */
    private function pointEditor(object $order): array
    {
        $snapshot = $this->orders->forOrder((int)$order->id);
        $texts = [];
        foreach (self::EDITOR_TEXTS as $key) {
            $texts[$key] = (string)$this->translations->getTranslation('sviat__rozetka__' . $key);
        }

        return [
            'orderId' => (int)$order->id,
            'needsChoice' => $snapshot === null,
            'locked' => OrderPointChange::locks($this->parcels->forOrder((int)$order->id)),
            'cityId' => $snapshot ? (string)$snapshot->city_id : null,
            'cityName' => $snapshot ? (string)$snapshot->city_name : null,
            'pointId' => $snapshot ? (string)$snapshot->point_id : null,
            'currency' => (string)($this->entityFactory->get(CurrenciesEntity::class)->getMainCurrency()->sign ?? ''),
            'textsJson' => json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ];
    }

    /** ChainExtension для BackendPaymentsRequest::postPayment: позначка післяплати з блоку payment_cod.tpl. */
    public function paymentPost($paymentMethod)
    {
        if (is_object($paymentMethod)) {
            $post = [];
            foreach ([CodPaymentInput::MARKER, CodPaymentInput::SWITCH] as $name) {
                $post[$name] = $this->request->post($name);
            }
            $flag = CodPaymentInput::flag($post, $paymentMethod->module ?? null, $this->codSettings->read()->enabled);
            if ($flag !== null) {
                $paymentMethod->{CodPayments::FIELD} = $flag;
            }
        }

        return $paymentMethod;
    }

    /** QueueExtension для BackendPaymentsHelper::getPaymentMethod: те, що показує payment_cod.tpl. */
    public function assignPaymentCod($payment, $id = null)
    {
        $manual = CodPayments::isManual(is_object($payment) ? ($payment->module ?? null) : null);
        $this->design->assign('rozetka_codpay', [
            'enabled' => $this->codSettings->read()->enabled,
            'on' => is_object($payment) && CodPayments::marked($payment),
            'manual' => $manual,
        ]);
    }

    /** QueueExtension для BackendDeliveriesHelper::getDelivery: попередження показуються лише на способах цього модуля. */
    public function deliveryWarning($delivery, $id = null): void
    {
        $moduleId = (int)$this->module->getModuleIdByNamespace(__CLASS__);
        $ours = is_object($delivery) && $moduleId > 0 && (int)($delivery->module_id ?? 0) === $moduleId && !empty($delivery->id);
        $warnings = [];
        if ($ours) {
            $ids = $this->entityFactory->get(DeliveriesEntity::class)->getDeliveryPayments((int)$delivery->id) ?: [];
            $payments = $ids === [] ? [] : ($this->entityFactory->get(PaymentsEntity::class)->noLimit()->find(['id' => $ids]) ?: []);
            $warnings = DeliveryWarnings::forDelivery($delivery, array_values($payments), $this->codSettings->read());
        }
        $this->design->assign('rozetka_delivery_warnings', $warnings);
    }
}
