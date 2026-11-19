<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Quote;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Money;
use Okay\Core\Settings;
use Okay\Entities\CurrenciesEntity;

/** Ціна доставки модуля для кошика й замовлення: з налаштувань, у основній валюті магазину. Запитів до API немає. */
final class CartQuotes
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Module $module,
        private readonly Money $money,
        private readonly EntityFactory $entityFactory,
    ) {
    }

    /**
     * @param float $goodsTotal сума товарів в основній валюті: з нею ядро порівнює free_from
     * @return ?DeliveryQuote null — спосіб доставки не цього модуля
     */
    public function quote(object $delivery, float $goodsTotal): ?DeliveryQuote
    {
        if (!$this->isOwnMethod($delivery)) {
            return null;
        }
        $config = ShippingConfig::fromSettings($this->settings->get(...));
        $quote = TariffPrice::quote($delivery, $config, $goodsTotal);

        return new DeliveryQuote(
            $this->fromUah($quote->price),
            $quote->source,
            $quote->receiverPrice === null ? null : $this->fromUah($quote->receiverPrice),
        );
    }

    public function isOwnMethod(object $delivery): bool
    {
        $moduleId = (int)$this->module->getModuleIdByNamespace(__CLASS__);

        return $moduleId > 0 && (int)($delivery->module_id ?? 0) === $moduleId;
    }

    /** Тариф у гривнях; ціни доставки OkayCMS — в основній валюті. Без валюти UAH основною вважається гривня. */
    private function fromUah(float $price): float
    {
        if ($price <= 0) {
            return 0.0;
        }
        $uah = $this->entityFactory->get(CurrenciesEntity::class)->findOne(['code' => 'UAH']);
        if (!$uah) {
            return $price;
        }

        // Точність 2 явно: за замовчуванням Money округлює до `cents` валюти UAH, а це може бути 0.
        return round((float)$this->money->convert($price, (int)$uah->id, false, true, 2), 2);
    }
}
