<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Orders;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Settings;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartMetrics;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartSelection;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\FitProblem;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PointFit;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsStore;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PendingParcel;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\CartQuotes;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryDate;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;

/** Менеджер змінює точку замовлення в адмінці. */
final class OrderPointChange
{
    /** Помилки кошика (мовні ключі вітрини) як ключі адмінки. */
    private const array ERRORS = [
        'sviat__rozetka__err_dictionary' => 'op_err_dictionary',
        'sviat__rozetka__err_city' => 'op_err_city',
        'sviat__rozetka__err_point' => 'op_err_point',
        'sviat__rozetka__err_cod_prepay_only' => 'op_err_cod_prepay_only',
        'sviat__rozetka__err_cod_over_limit' => 'op_err_cod_over_limit',
    ];
    /** Вага, габарити й оголошена — рішення менеджера (він знає справжню посилку), тож лише попередження. */
    private const array WARNINGS = [
        FitProblem::TooHeavy->value => 'op_warn_too_heavy',
        FitProblem::TooLong->value => 'op_warn_too_big',
        FitProblem::TooBulky->value => 'op_warn_too_big',
        FitProblem::OverCost->value => 'op_warn_over_cost',
    ];

    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly Module $module,
        private readonly GroupsStore $groups,
        private readonly OrderDeliveryStore $snapshots,
        private readonly OrderParcel $parcel,
        private readonly CartQuotes $quotes,
        private readonly OrderDeliveryLines $lines,
        private readonly Settings $settings,
        private readonly CodPayments $codPayments,
        private readonly DeliveryDate $dates,
        private readonly ParcelRows $parcels,
    ) {
    }

    /** @param array{city_id?: string, point_id?: string} $input */
    public function save(int $orderId, array $input): OrderPointResult
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);
        $delivery = $order ? $this->lines->ownDelivery($order) : null;
        if (!$order || !$delivery) {
            return OrderPointResult::failed('order_point_foreign');
        }
        [$cityId, $pointId] = CartSelectionValidator::postedIds(
            [CartSelectionValidator::CITY_FIELD => [0 => $input['city_id'] ?? ''], CartSelectionValidator::POINT_FIELD => [0 => $input['point_id'] ?? '']],
            0,
        );
        $city = $cityId !== '' ? $this->entityFactory->get(CityEntity::class)->get($cityId) : null;
        $point = $pointId !== '' ? $this->entityFactory->get(PointEntity::class)->get($pointId) : null;
        $cod = $this->codPayments->isCod((int)($order->payment_method_id ?? 0));
        $chosen = self::select(
            $delivery,
            (int)$this->module->getModuleIdByNamespace(__CLASS__),
            $city ?: null,
            $point ?: null,
            $this->groups->all(),
            $this->parcel->metrics($order),
            ShippingConfig::fromSettings($this->settings->get(...)),
            $cod,
            $this->parcels->forOrder($orderId),
        );
        if ($chosen['selection'] === null) {
            return OrderPointResult::failed((string)$chosen['error']);
        }
        $price = $this->price($order, $delivery);
        // Ціну й комісію знімок не змінює: це рішення оформлення або менеджера (applyPrice).
        $this->snapshots->save($orderId, $chosen['selection'], [
            'cod' => $cod ? 1 : 0,
            'estimated_date' => OrderDeliveryStore::date($this->dates->estimate($pointId)),
        ]);
        return new OrderPointResult(true, null, $chosen['warning'], $price);
    }

    /** Записує тариф до поточної точки замовлення; лише коли правило його пропонує, ніколи — надіслану суму. */
    public function applyPrice(int $orderId): OrderPointResult
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->get($orderId);
        $delivery = $order ? $this->lines->ownDelivery($order) : null;
        if (!$order || !$delivery || !$this->snapshots->forOrder($orderId)) {
            return OrderPointResult::failed('order_point_foreign');
        }
        $price = $this->price($order, $delivery);
        if ($price['action'] !== 'offer') {
            return new OrderPointResult(false, 'order_point_price_not_offered', null, $price);
        }
        $this->writePrice($orderId, (float)$price['price']);

        return new OrderPointResult(true, null, null, ['action' => 'applied', 'price' => $price['price']]);
    }

    /**
     * Перевіряє обрану точку так само, як кошик. Післяплату в точку «лише передоплата» не пропускає й менеджер:
     * точка відмовить у видачі, і посилка повернеться.
     *
     * @param list<object> $groupRows рядки GroupEntity
     * @param ?object $parcel рядок ParcelEntity замовлення
     * @return array{selection: ?CartSelection, error: ?string, warning: ?string}
     */
    public static function select(object $delivery, int $moduleId, ?object $city, ?object $point, array $groupRows, CartMetrics $metrics, ShippingConfig $box, bool $cod, ?object $parcel = null): array
    {
        $fail = static fn(string $error): array => ['selection' => null, 'error' => $error, 'warning' => null];
        if (self::locks($parcel)) {
            return $fail(PendingParcel::is($parcel) ? 'op_err_locked_check' : 'op_err_locked');
        }
        if ($moduleId === 0 || (int)($delivery->module_id ?? 0) !== $moduleId) {
            return $fail('order_point_foreign');
        }
        $checked = CartSelectionValidator::check($delivery, $moduleId, $city, $point, $cod, $metrics, $box, $groupRows);
        if ($checked instanceof CartSelection) {
            return $checked->applies() ? ['selection' => $checked, 'error' => null, 'warning' => null] : $fail('order_point_foreign');
        }
        if (isset(self::ERRORS[$checked]) || $point === null || $city === null) {
            return $fail(self::ERRORS[$checked] ?? 'op_err_point');
        }
        $problems = PointFit::problems($metrics, $box, $cod, $point);
        if ($problems === []) {
            return $fail('op_err_point');
        }

        return ['selection' => new CartSelection($delivery, $city, $point, $cod), 'error' => null, 'warning' => self::WARNINGS[$problems[0]->value] ?? null];
    }

    /**
     * ЕН створено на точку замовлення (або створення ще не з'ясоване): нова точка в замовленні розійшлася б із
     * накладною. Будь-який рядок ЕН закриває зміну точки, доки ЕН не видалено чи не відв'язано.
     */
    public static function locks(?object $parcel): bool
    {
        return $parcel !== null;
    }

    /** @return array{action: string, price: ?float} */
    private function price(object $order, object $delivery): array
    {
        $goods = OrderParcel::asCart($order, [])->total_price;
        $quote = $this->quotes->quote($delivery, (float)$goods);

        return OrderPriceRule::decide($order, $delivery, $quote?->price);
    }

    private function writePrice(int $orderId, float $price): void
    {
        $orders = $this->entityFactory->get(OrdersEntity::class);
        $orders->update($orderId, ['delivery_price' => $price]);
        $orders->updateTotalPrice($orderId);
        $this->snapshots->setPrice($orderId, $price, 'manager');
    }
}
