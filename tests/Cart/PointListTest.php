<?php

namespace Modules\Sviat\RozetkaDelivery\Cart;

require_once __DIR__ . '/PointFitTest.php';

use Okay\Modules\Sviat\RozetkaDelivery\Cart\CartMetrics;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PointList;
use PHPUnit\Framework\TestCase;

class PointListTest extends TestCase
{
    private const string TODAY = '2026-11-18';

    private static function groupRows(): array
    {
        return [
            (object)['id' => 1, 'name' => 'Точки видачі', 'enabled' => 1, 'position' => 1, 'kinds' => '["pickup","store"]'],
            (object)['id' => 2, 'name' => 'Поштомати', 'enabled' => 1, 'position' => 2, 'kinds' => '["postomat"]'],
            (object)['id' => 3, 'name' => 'Партнерські', 'enabled' => 1, 'position' => 3, 'kinds' => '["partner"]'],
        ];
    }

    private static function point(int $n, string $number, string $kind = 'pickup', array $extra = []): object
    {
        return PointFitTest::point($extra + [
            'id' => sprintf('aaaaaaaa-0000-4000-8000-%012d', $n),
            'number' => $number,
            'kind' => $kind,
            'title' => "№{$number}, м. Тестове, вул. Вигадана, {$n}",
            'address' => "вул. Вигадана {$n}",
            'schedule' => '["Пн: 08:30-18:00"]',
            'extra_schedule' => null,
        ]);
    }

    private static function build(array $points, ?int $group, string $query = '', bool $cod = false, ?CartMetrics $cart = null): array
    {
        return PointList::build($points, self::groupRows(), $cart ?? new CartMetrics(1.0, false, 100.0, 100.0), PointFitTest::box(), $cod, self::TODAY, $group, $query);
    }

    public function testReceiveOnlyHidden(): void
    {
        $result = self::build([self::point(1, '10001'), self::point(2, '10002', 'pickup', ['can_give_out' => 0])], null);

        self::assertSame([['id' => 1, 'name' => 'Точки видачі', 'count' => 1]], $result['groups']);
        self::assertSame(['10001'], array_column($result['points'], 'number'), 'a point that only receives parcels is never offered');
    }

    public function testPointsCarryPrepayAndProblems(): void
    {
        $partner = self::point(3, '20001', 'partner', ['type_id' => 14, 'prepay_only' => 1]);

        $plain = self::build([$partner], 3)['points'][0];
        self::assertTrue($plain['prepay_only']);
        self::assertSame([], $plain['problems']);
        self::assertSame(['prepay_only'], $plain['cod_problems'], 'the script greys it out once a cash-on-delivery payment is chosen');
        self::assertFalse($plain['disabled']);

        $cod = self::build([$partner], 3, '', true)['points'][0];
        self::assertSame(['prepay_only'], $cod['problems']);
        self::assertTrue($cod['disabled']);
        self::assertSame(['from' => 0, 'to' => 0, 'hours' => '08:30–18:00', 'break' => null], $cod['schedule']['week'][0]);
    }

    public function testFittingFirstAndSearchByNumberOrStreet(): void
    {
        $points = [
            self::point(1, '10010', 'pickup', ['max_weight' => '1.000']),
            self::point(2, '10002'),
            self::point(3, '10100', 'postomat'),
            self::point(4, '10001'),
        ];

        self::assertSame(['10001', '10002', '10010'], array_column(self::build($points, 1, '', false, new CartMetrics(2.0, false, 100.0, 100.0))['points'], 'number'), 'unfit points go last');
        self::assertSame(['10002'], array_column(self::build($points, 1, '10002')['points'], 'number'));
        self::assertSame(['10001'], array_column(self::build($points, 1, 'Вигадана 4')['points'], 'number'));
        self::assertSame(['10100'], array_column(self::build($points, 2)['points'], 'number'));
    }
}
