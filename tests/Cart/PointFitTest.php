<?php

namespace Modules\Sviat\RozetkaDelivery\Cart;

use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartMetrics;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\FitProblem;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PointFit;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class PointFitTest extends TestCase
{
    /** @param array<string, mixed> $settings */
    public static function box(int $l = 30, int $w = 20, int $h = 15, array $settings = []): ShippingConfig
    {
        $values = $settings + [ShippingConfig::BOX_LENGTH => $l, ShippingConfig::BOX_WIDTH => $w, ShippingConfig::BOX_HEIGHT => $h];

        return ShippingConfig::fromSettings(static fn(string $key): mixed => $values[$key] ?? null);
    }

    public static function point(array $override = []): object
    {
        return (object)($override + [
            'id' => 'aaaaaaaa-0000-4000-8000-000000000001',
            'city_id' => 'cccccccc-0000-4000-8000-000000000001',
            'kind' => 'pickup',
            'type_id' => 2,
            'max_weight' => null, 'max_volume_weight' => null, 'max_length' => null, 'max_cost' => null,
            'can_give_out' => 1,
            'prepay_only' => 0,
        ]);
    }

    private static function cart(float $weight = 1.0, float $total = 1000.0): CartMetrics
    {
        return new CartMetrics($weight, false, $total, $total);
    }

    public function testNetworkLimits(): void
    {
        self::assertSame([], PointFit::problems(self::cart(15.0), self::box(), false, self::point()), 'exactly the network weight fits');
        self::assertSame([FitProblem::TooHeavy], PointFit::problems(self::cart(16.0), self::box(), false, self::point()));
        self::assertSame([FitProblem::TooLong], PointFit::problems(self::cart(), self::box(121, 10, 10), false, self::point()));
        self::assertSame([FitProblem::TooBulky], PointFit::problems(self::cart(), self::box(70, 50, 40), false, self::point()), '70×50×40/4000 = 35 kg');
        self::assertSame([], PointFit::problems(self::cart(), self::box(60, 50, 40), false, self::point()), '60×50×40/4000 = 30 kg fits');
        self::assertSame([FitProblem::OverCost], PointFit::problems(self::cart(1.0, 30000.01), self::box(), false, self::point()));
    }

    public function testPointLimitBelowNetwork(): void
    {
        $point = self::point(['max_weight' => '10.000', 'max_volume_weight' => '5.000', 'max_length' => '40.000', 'max_cost' => '20000.00']);

        self::assertSame([FitProblem::TooHeavy], PointFit::problems(self::cart(10.5), self::box(), false, $point));
        self::assertSame([FitProblem::TooLong], PointFit::problems(self::cart(), self::box(41, 10, 10), false, $point));
        self::assertSame([FitProblem::TooBulky], PointFit::problems(self::cart(), self::box(30, 30, 30), false, $point), '30³/4000 = 6.75 kg over 5');
        self::assertSame([FitProblem::OverCost], PointFit::problems(self::cart(1.0, 20001.0), self::box(), false, $point));
        self::assertSame([], PointFit::problems(self::cart(), self::box(), false, self::point(['max_weight' => '150.000', 'max_cost' => '60000.00'])), 'a point above the network limit is capped, not relaxed');
        self::assertSame([FitProblem::TooHeavy], PointFit::problems(self::cart(16.0), self::box(), false, self::point(['max_weight' => '150.000'])));
    }

    public function testDeclaredFollowsSettings(): void
    {
        $fixed = self::box(30, 20, 15, [ShippingConfig::DECLARE_TOTAL => '0', ShippingConfig::DECLARED_VALUE => '500']);

        self::assertSame([], PointFit::problems(self::cart(1.0, 40000.0), $fixed, false, self::point()), 'a fixed declared value under the limit fits whatever the cart costs');
    }

    public function testPrepayOnlyOnlyWithCod(): void
    {
        $partner = self::point(['kind' => 'partner', 'type_id' => 14, 'prepay_only' => 1]);

        self::assertSame([], PointFit::problems(self::cart(), self::box(), false, $partner), 'prepaid orders go to partner points');
        self::assertSame([FitProblem::PrepayOnly], PointFit::problems(self::cart(), self::box(), true, $partner));
    }

    public function testCodOverPointCost(): void
    {
        $fixed = self::box(30, 20, 15, [ShippingConfig::DECLARE_TOTAL => '0', ShippingConfig::DECLARED_VALUE => '500']);
        $point = self::point(['max_cost' => '20000.00']);

        self::assertSame([FitProblem::CodOverLimit], PointFit::problems(self::cart(1.0, 25000.0), $fixed, true, $point), 'cash on delivery cannot exceed what the point may declare');
        self::assertSame([], PointFit::problems(self::cart(1.0, 25000.0), $fixed, false, $point));
        self::assertSame([FitProblem::CodOverLimit], PointFit::problems(self::cart(1.0, 25000.0), self::box(), true, $point), 'one reason, not two, when the declared total is the same sum');
    }
}
