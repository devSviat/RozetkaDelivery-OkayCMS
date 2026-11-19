<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Cart;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Settings;
use Okay\Entities\DeliveriesEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointKind;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsStore;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;

/**
 * Перевіряє на сервері те, що каже форма кошика: браузер може надіслати будь-які id, а скрипт кошика,
 * що сірить точки «лише передоплата», — лише зручність. Формі не довіряємо ні в чому, крім двох UUID.
 */
final class CartSelectionValidator
{
    public const string CITY_FIELD = 'rozetka_city_id';
    public const string POINT_FIELD = 'rozetka_point_id';
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Перша проблема визначає текст; «лише передоплата» стоїть першою, бо її покупець може виправити оплатою. */
    private const array FIT_ERRORS = [
        FitProblem::PrepayOnly->value => 'sviat__rozetka__err_cod_prepay_only',
        FitProblem::CodOverLimit->value => 'sviat__rozetka__err_cod_over_limit',
        FitProblem::TooHeavy->value => 'sviat__rozetka__err_too_heavy',
        FitProblem::TooLong->value => 'sviat__rozetka__err_too_big',
        FitProblem::TooBulky->value => 'sviat__rozetka__err_too_big',
        FitProblem::OverCost->value => 'sviat__rozetka__err_over_cost',
    ];

    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly Module $module,
        private readonly GroupsStore $groups,
        private readonly CodPayments $codPayments,
        private readonly DictionaryReady $dictionary,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param CartMetrics $cart метрики з сумою в гривнях (UahTotal)
     * @return CartSelection|string вибір або мовний ключ помилки, яку треба показати
     */
    public function validate(object $order, array $post, CartMetrics $cart): CartSelection|string
    {
        $deliveryId = (int)($order->delivery_id ?? 0);
        $delivery = $deliveryId > 0 ? $this->entityFactory->get(DeliveriesEntity::class)->get($deliveryId) : null;
        $moduleId = $this->moduleId();
        if (!$delivery || (int)($delivery->module_id ?? 0) !== $moduleId) {
            return CartSelection::none();
        }
        [$cityId, $pointId] = self::postedIds($post, $deliveryId);
        $city = $cityId !== '' ? $this->entityFactory->get(CityEntity::class)->get($cityId) : null;
        $point = $pointId !== '' ? $this->entityFactory->get(PointEntity::class)->get($pointId) : null;
        $ready = $this->dictionary->ready();
        // Точка зникла з довідника між показом кошика й оформленням.
        if ($ready && $city && $pointId !== '' && !$point) {
            return 'sviat__rozetka__err_point_gone';
        }

        return self::check(
            $delivery,
            $moduleId,
            $city ?: null,
            $point ?: null,
            $this->codPayments->isCod((int)($order->payment_method_id ?? 0)),
            $cart,
            ShippingConfig::fromSettings($this->settings->get(...)),
            $this->groups->all(),
            $ready,
        );
    }

    /**
     * Точка, яку відновлює кошик (надіслана форма, сесія, останнє замовлення), або null, коли оформлення
     * зараз її відхилило б. Оплата ще не відома: післяплату перевіряє оформлення.
     */
    public function restorable(object $delivery, ?object $city, ?object $point, CartMetrics $cart): ?object
    {
        if ($point === null) {
            return null;
        }
        $config = ShippingConfig::fromSettings($this->settings->get(...));

        return self::check($delivery, $this->moduleId(), $city, $point, false, $cart, $config, $this->groups->all()) instanceof CartSelection ? $point : null;
    }

    /** @param list<object> $groupRows рядки GroupEntity */
    public static function check(
        ?object $delivery,
        int $moduleId,
        ?object $city,
        ?object $point,
        bool $cod,
        CartMetrics $cart,
        ShippingConfig $box,
        array $groupRows,
        bool $dictionaryReady = true,
    ): CartSelection|string {
        if ($delivery === null || $moduleId === 0 || (int)($delivery->module_id ?? 0) !== $moduleId) {
            return CartSelection::none();
        }
        if (!$dictionaryReady) {
            return 'sviat__rozetka__err_dictionary';
        }
        if ($city === null) {
            return 'sviat__rozetka__err_city';
        }
        // Кошик не показує точок іншого міста, лише прийому чи видів поза увімкненими групами, тож і форма не може їх обрати.
        if (
            $point === null
            || PointKind::tryFrom((string)($point->kind ?? '')) === null
            || (string)$point->city_id !== (string)$city->id
            || (int)($point->can_give_out ?? 0) !== 1
            || !in_array((string)$point->kind, PointList::visibleKinds($groupRows), true)
        ) {
            return 'sviat__rozetka__err_point';
        }
        $problems = PointFit::problems($cart, $box, $cod, $point);
        foreach (self::FIT_ERRORS as $problem => $error) {
            if (in_array(FitProblem::from($problem), $problems, true)) {
                return $error;
            }
        }

        return new CartSelection($delivery, $city, $point, $cod);
    }

    /** @return array{0:string, 1:string} UUID міста й точки, надіслані для цього способу доставки; '' — немає */
    public static function postedIds(array $post, int $deliveryId): array
    {
        $read = static function (string $field) use ($post, $deliveryId): string {
            $value = is_array($post[$field] ?? null) ? ($post[$field][$deliveryId] ?? null) : null;
            $value = is_string($value) ? strtolower(trim($value)) : '';

            return preg_match(self::UUID, $value) === 1 ? $value : '';
        };

        return [$read(self::CITY_FIELD), $read(self::POINT_FIELD)];
    }

    private function moduleId(): int
    {
        return (int)$this->module->getModuleIdByNamespace(__CLASS__);
    }
}
