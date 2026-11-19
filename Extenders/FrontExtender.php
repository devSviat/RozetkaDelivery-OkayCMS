<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Extenders;

use Okay\Core\Cart;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Core\Router;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartMetrics;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartSelection;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartSelectionValidator;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\DictionaryReady;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PointList;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PopularCities;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\ThemeClasses;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\UahTotal;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\WorkSchedule;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\CartQuotes;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryDate;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryQuote;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryPriceView;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\OrderPrice;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\QuoteSession;

class FrontExtender implements ExtensionInterface
{
    /** Тексти, потрібні скрипту кошика; ключі design/lang/*.php без префікса. */
    private const array SCRIPT_TEXTS = [
        'cart_no_results', 'cart_shown_of', 'cart_all_day', 'cart_day_off', 'cart_break', 'cart_schedule', 'cart_special',
        'cart_too_heavy', 'cart_too_long', 'cart_too_bulky', 'cart_over_cost', 'cart_prepay_only', 'cart_cod_over_limit',
        'cart_estimated', 'cart_no_points', 'cart_search_failed', 'cart_no_city', 'cart_busy', 'cart_none_fit',
        'cart_kind_pickup', 'cart_kind_store', 'cart_kind_postomat', 'cart_kind_partner', 'cart_kind_other',
        'cart_cod_fee', 'cart_cod_payment_locked', 'cart_weekdays',
        'err_city', 'err_point', 'err_cod_prepay_only',
    ];

    /** Маршрути кошика, які скрипт отримує в data-urls. */
    private const array ROUTES = [
        'cities' => 'Sviat_RozetkaDelivery_cart_cities',
        'points' => 'Sviat_RozetkaDelivery_cart_points',
        'date' => 'Sviat_RozetkaDelivery_cart_date',
    ];

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Вибір, перевірений getCartValidateError; зберігається, щойно замовлення створено (той самий запит). */
    private ?CartSelection $selection = null;

    /** Як розраховано ціну замовлення; зберігається від prepareDeliveryPriceInfo до знімка (той самий запит). */
    private ?array $pricing = null;

    public function __construct(
        private readonly Design $design,
        private readonly Settings $settings,
        private readonly Module $module,
        private readonly EntityFactory $entityFactory,
        private readonly FrontTranslations $translations,
        private readonly CartSelectionValidator $validator,
        private readonly OrderDeliveryStore $orders,
        private readonly Cart $cart,
        private readonly CartQuotes $quotes,
        private readonly Money $money,
        private readonly UahTotal $uahTotal,
        private readonly PopularCities $popularCities,
        private readonly DictionaryReady $dictionary,
        private readonly OrderDeliveryLines $orderLines,
        private readonly CodPayments $codPayments,
        private readonly CodSettings $codSettings,
        private readonly DeliveryDate $dates,
        private readonly QuoteSession $session,
    ) {
    }

    /**
     * ChainExtension для DeliveriesHelper::getCartDeliveriesList: способи модуля показують ціну з налаштувань,
     * також після того, як тема перезавантажить кошик через AJAX.
     */
    public function applyQuotes($deliveries, $cart)
    {
        if (!is_array($deliveries) || !is_object($cart)) {
            return $deliveries;
        }
        $total = (float)($cart->total_price ?? 0);
        foreach ($deliveries as $delivery) {
            $quote = is_object($delivery) ? $this->quotes->quote($delivery, $total) : null;
            if ($quote !== null) {
                $this->showPrice($delivery, $quote, $total);
            }
        }

        return $deliveries;
    }

    /**
     * ChainExtension для DeliveriesHelper::prepareDeliveryPriceInfo: ціна доставки замовлення — рішення сервера
     * для точки, яку перевірив getCartValidateError у цьому ж запиті; ціна з форми не читається.
     */
    public function orderPrice($info, $delivery, $order)
    {
        if (!is_array($info) || !is_object($delivery) || !is_object($order) || !$this->quotes->isOwnMethod($delivery)) {
            return $info;
        }
        $point = $this->selection?->point;
        if ($point === null) {
            return $info;
        }
        $goods = (float)($order->total_price ?? 0);
        $decision = OrderPrice::decide($this->quotes->quote($delivery, $goods), $info, $delivery, $order);
        $price = is_numeric($decision['info']['delivery_price'] ?? null) ? (float)$decision['info']['delivery_price'] : null;
        $cod = $this->selection->cod;
        // Сума до сплати в точці: товари й доставка, якщо вона входить у суму замовлення.
        $due = $goods + (empty($decision['info']['separate_delivery']) ? (float)$price : 0.0);
        $this->pricing = OrderDeliveryStore::pricing(
            $price,
            $decision['source'],
            $cod,
            $cod ? $this->codSettings->read()->estimateFee($due) : null,
            $this->dates->cached((string)$point->id),
        );

        return $decision['info'];
    }

    /**
     * ChainExtension для OrdersHelper::isPaymentMethodOffered: на сторінці замовлення покупець не перемкне оплату
     * на післяплату, якщо точка замовлення видає лише передоплачені. Оформлення з кошика перевіряє валідатор.
     */
    public function paymentOffered($offered, $order = null, $paymentMethodId = null)
    {
        if (!$offered || !is_object($order) || empty($order->id) || !$this->orderLines->ownDelivery($order)) {
            return $offered;
        }
        $snapshot = $this->orders->forOrder((int)$order->id);
        if ($snapshot && (int)($snapshot->prepay_only ?? 0) === 1 && $this->codPayments->isCod((int)$paymentMethodId)) {
            return false;
        }

        return $offered;
    }

    /** ChainExtension для ValidateHelper::getCartValidateError: форма кошика перевіряється на сервері. */
    public function getCartValidateError($error, $order)
    {
        // Ланка контактів раніше в ланцюжку може зняти свою помилку і як '' (контракт ядра це дозволяє).
        if (($error !== null && $error !== '') || !is_object($order)) {
            return $error;
        }
        $result = $this->validator->validate($order, $_POST, $this->uahTotal->metrics($this->cart->get()));
        if (is_string($result)) {
            // Порожня помилка означала б, що перевірку пройдено, тож без перекладу лишається ключ.
            return $this->translations->getTranslation($result) ?: $result;
        }
        $this->selection = $result;

        return null;
    }

    /** QueueExtension для OrdersHelper::finalCreateOrderProcedure. */
    public function saveOrderDelivery($result, $order)
    {
        if (!is_object($order) || empty($order->id)) {
            return;
        }
        $selection = $this->selection ?? $this->validator->validate($order, $_POST, $this->uahTotal->metrics($this->cart->get()));
        if ($selection instanceof CartSelection) {
            $this->orders->save((int)$order->id, $selection, $this->pricing);
        }
    }

    /**
     * QueueExtension для OrdersHelper::getOrderPaymentMethodsList і NotifyHelper::finalEmailOrderUser/Admin:
     * сторінка замовлення й обидва листи показують збережену точку. Для інших замовлень
     * присвоюється null, бо один запит може рендерити листи кількох замовлень з тим самим Design.
     */
    public function assignOrderDelivery($result, $order)
    {
        $this->design->assign('rozetka_order_delivery', is_object($order) ? $this->orderLines->forOrder($order) : null);
        $this->design->assign('rozetka_order_family', ThemeClasses::for((string)$this->settings->get('theme'))['family']);
    }

    /** QueueExtension для DeliveriesHelper::getCartDeliveriesList: дані для блоків кошика, лише для способів цього модуля. */
    public function getCartDeliveriesList($deliveries, $cart)
    {
        $moduleId = (int)$this->module->getModuleIdByNamespace(__CLASS__);
        $own = array_filter((array)$deliveries, static fn($d): bool => is_object($d) && $moduleId > 0 && (int)($d->module_id ?? 0) === $moduleId);
        if ($own === []) {
            return;
        }

        $texts = [];
        foreach (self::SCRIPT_TEXTS as $key) {
            $texts[$key] = (string)$this->translations->getTranslation('sviat__rozetka__' . $key);
        }
        $urls = [];
        foreach (self::ROUTES as $name => $route) {
            $urls[$name] = (string)Router::generateUrl($route, [], true);
        }

        $this->design->assign('rozetka_module_id', $moduleId);
        $this->design->assign('rozetka_unavailable', !$this->dictionary->ready());
        $this->design->assign('rozetka_theme', ThemeClasses::for((string)$this->settings->get('theme')));
        $this->design->assign('rozetka_popular', $this->popularCities->buttons());
        // JSON будується тут: Smarty 5 не дозволяє PHP-функції на кшталт json_encode як модифікатори.
        $this->design->assign('rozetka_texts_json', json_encode($texts, JSON_UNESCAPED_UNICODE));
        $this->design->assign('rozetka_urls_json', json_encode($urls, JSON_UNESCAPED_SLASHES));
        $this->design->assign('rozetka_prefill', $this->prefill($own, is_object($cart) ? $cart : (object)[]));
        $this->design->assign('rozetka_cod', $this->codView($own, is_object($cart) ? $cart : (object)[]));
    }

    /**
     * Способи оплати з позначкою й оцінка комісії для кожного способу доставки модуля.
     *
     * @param array<object> $deliveries
     * @return array<int, array{payments:string, fee:string}>
     */
    public function codView(array $deliveries, object $cart): array
    {
        $config = $this->codSettings->read();
        $view = [];
        foreach ($deliveries as $delivery) {
            $ids = is_array($delivery->payment_methods_ids ?? null) ? $delivery->payment_methods_ids : [];
            $cod = array_values(array_filter(array_map(intval(...), $ids), $this->codPayments->isCod(...)));
            $due = is_numeric($delivery->total_price_with_delivery ?? null) ? (float)$delivery->total_price_with_delivery : (float)($cart->total_price ?? 0);
            // Нульова формула означає, що комісію платить магазин: рядок не показується.
            $showFee = $cod !== [] && ($config->feeFixed > 0 || $config->feePercent > 0);
            $view[(int)$delivery->id] = [
                'payments' => implode(',', $cod),
                // Точність 2 явно: Money типово округлює до `cents` валюти, а це може бути 0.
                'fee' => $showFee ? $this->money->convert($config->estimateFee($due), null, true, false, 2) . ' ' . $this->currencySign() : '',
            ];
        }

        return $view;
    }

    /**
     * Вибір, який покупець уже зробив; показується знову, коли сторінка кошика повертається з помилкою.
     *
     * @param array<object> $deliveries способи доставки цього модуля
     * @return array<int, array{city:?array, point:?array, city_json:string, point_json:string}>
     */
    private function prefill(array $deliveries, object $cart): array
    {
        // Без надісланої форми попереднє замовлення покупця знову пропонує свою точку.
        $posted = isset($_POST[CartSelectionValidator::CITY_FIELD]) || isset($_POST[CartSelectionValidator::POINT_FIELD]);
        $user = $this->design->getVar('user');
        $last = !$posted && is_object($user) ? $this->orders->lastForUser((int)($user->id ?? 0)) : null;
        $metrics = $this->uahTotal->metrics($cart);
        $prefill = [];
        foreach ($deliveries as $delivery) {
            $deliveryId = (int)$delivery->id;
            [$cityId, $pointId] = CartSelectionValidator::postedIds($_POST, $deliveryId);
            $choice = self::prefillChoice($posted ? ['city_id' => $cityId, 'point_id' => $pointId] : null, $this->sessionChoice($deliveryId), $last);
            $described = $this->describe($delivery, $metrics, (string)($choice['city_id'] ?? ''), (string)($choice['point_id'] ?? ''));
            $prefill[$deliveryId] = $described + [
                'city_json' => $described['city'] ? (string)json_encode($described['city'], JSON_UNESCAPED_UNICODE) : '',
                'point_json' => $described['point'] ? (string)json_encode($described['point'], JSON_UNESCAPED_UNICODE) : '',
            ];
        }

        return $prefill;
    }

    /**
     * Надіслана форма, інакше вибір цієї сесії, інакше останнє замовлення.
     *
     * @param ?array{city_id:string, point_id:string} $posted
     * @param array{city_id?:string, point_id?:string} $session
     * @return array{city_id?:string, point_id?:string}
     */
    public static function prefillChoice(?array $posted, array $session, ?object $last): array
    {
        if ($posted !== null) {
            return $posted;
        }
        if ($session !== []) {
            return $session;
        }

        return $last ? ['city_id' => (string)$last->city_id, 'point_id' => (string)$last->point_id] : [];
    }

    /** @return array{city_id?:string, point_id?:string} */
    private function sessionChoice(int $deliveryId): array
    {
        $pointId = (string)$this->session->chosen($deliveryId);
        $point = preg_match(self::UUID, $pointId) === 1 ? $this->entityFactory->get(PointEntity::class)->get($pointId) : null;

        return $point ? ['city_id' => (string)$point->city_id, 'point_id' => $pointId] : [];
    }

    /** @return array{city:?array, point:?array} UUID лише з таблиць: id з форми чи старого замовлення шукаються, а не довіряються */
    private function describe(object $delivery, CartMetrics $cart, string $cityId, string $pointId): array
    {
        $city = preg_match(self::UUID, $cityId) === 1 ? $this->entityFactory->get(CityEntity::class)->get($cityId) : null;
        $point = $city && preg_match(self::UUID, $pointId) === 1 ? $this->entityFactory->get(PointEntity::class)->get($pointId) : null;
        // Точка, яку оформлення зараз відхилило б, повертається до списку, де показано її проблему.
        $point = $point ? $this->validator->restorable($delivery, $city, $point, $cart) : null;

        return [
            'city' => $city ? [
                'id' => (string)$city->id,
                'name' => (string)$city->name_ua,
                'type' => (string)($city->type ?? ''),
                'district' => (string)($city->district_ua ?? ''),
                'region' => (string)($city->region_ua ?? ''),
            ] : null,
            'point' => $point ? [
                'id' => (string)$point->id,
                'number' => (string)($point->number ?? ''),
                'kind' => (string)$point->kind,
                'title' => (string)$point->title,
                'address' => (string)($point->address ?? ''),
                'prepay_only' => (int)($point->prepay_only ?? 0) === 1,
                'schedule' => WorkSchedule::fromColumns($point->schedule ?? null, $point->extra_schedule ?? null, PointList::today(time())),
            ] : null,
        ];
    }

    private function showPrice(object $delivery, DeliveryQuote $quote, float $cartTotal): void
    {
        DeliveryPriceView::apply(
            $delivery,
            $quote,
            $cartTotal,
            fn(float $amount): string => (string)$this->money->convert($amount),
            $this->currencySign(),
            [
                'free' => (string)$this->translations->getTranslation('cart_free'),
                'separate' => (string)$this->translations->getTranslation('cart_paid_separate'),
                'receiver' => (string)$this->translations->getTranslation('sviat__rozetka__cart_price_receiver'),
                'receiver_plain' => (string)$this->translations->getTranslation('sviat__rozetka__cart_price_receiver_plain'),
            ],
        );
    }

    private function currencySign(): string
    {
        $currencies = $this->entityFactory->get(CurrenciesEntity::class);
        $currency = $currencies->get((int)($_SESSION['currency_id'] ?? 0)) ?: $currencies->getMainCurrency();

        return (string)($currency->sign ?? '');
    }
}
