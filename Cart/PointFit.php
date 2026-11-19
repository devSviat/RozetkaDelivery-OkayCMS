<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Cart;

use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointLimits;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;

/**
 * Чи прийме точка посилку кошика: ліміти точки з довідника, обмежені мережевими (тарифи: 120 см, 15 кг,
 * об'ємна 30 кг, оголошена 30 000 грн). Вага — відома частина кошика; товари без ваги її не збільшують.
 */
final class PointFit
{
    /** Об'ємна вага, кг: Д×Ш×В у сантиметрах ділиться на 4000 (тарифи ROZETKA Delivery). */
    public const int VOLUME_DIVISOR = 4000;

    /** @return list<FitProblem> */
    public static function problems(CartMetrics $cart, ShippingConfig $box, bool $cod, object $point): array
    {
        $limits = self::limits($point);
        $problems = [];
        if ($cart->knownWeight > $limits['max_weight'] + 1e-9) {
            $problems[] = FitProblem::TooHeavy;
        }
        if (max($box->lengthCm, $box->widthCm, $box->heightCm) > $limits['max_length'] + 1e-9) {
            $problems[] = FitProblem::TooLong;
        }
        if (self::volumeWeight($box) > $limits['max_volume_weight'] + 1e-9) {
            $problems[] = FitProblem::TooBulky;
        }
        $total = $cart->totalUah ?? $cart->total;
        if ($cod && $total > $limits['max_cost'] + 1e-9) {
            // Оголошена не менша за суму післяплати, тож у межу точки не вміщається саме післяплата.
            $problems[] = FitProblem::CodOverLimit;
        } elseif (self::declared($box, $total) > $limits['max_cost'] + 1e-9) {
            $problems[] = FitProblem::OverCost;
        }
        if ($cod && (int)($point->prepay_only ?? 0) === 1) {
            $problems[] = FitProblem::PrepayOnly;
        }

        return $problems;
    }

    /** Оголошена вартість, грн: сума кошика або фіксоване значення з налаштувань. */
    public static function declared(ShippingConfig $box, float $totalUah): float
    {
        return $box->declareTotal ? $totalUah : $box->declaredValue;
    }

    public static function volumeWeight(ShippingConfig $box): float
    {
        return $box->lengthCm * $box->widthCm * $box->heightCm / self::VOLUME_DIVISOR;
    }

    /** @return array{max_weight: float, max_volume_weight: float, max_length: float, max_cost: float} */
    private static function limits(object $point): array
    {
        $number = static fn(mixed $value): ?float => is_numeric($value) && (float)$value > 0 ? (float)$value : null;

        return new PointLimits(
            $number($point->max_weight ?? null),
            $number($point->max_volume_weight ?? null),
            $number($point->max_length ?? null),
            $number($point->max_cost ?? null),
            false,
        )->effective();
    }
}
