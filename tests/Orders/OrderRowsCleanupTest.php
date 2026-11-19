<?php

namespace Modules\Sviat\RozetkaDelivery\Orders;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\RozetkaDelivery\Support\InMemoryState;
use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Init\Init;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\ExistingOrders;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderRowsCleanup;
use PHPUnit\Framework\TestCase;

/**
 * Видалене замовлення забирає з собою знімок точки; Rozetka не питають.
 * Знімки — єдине місце, де лишається точка замовлення: збій запиту до замовлень не повинен їх стерти.
 */
class OrderRowsCleanupTest extends TestCase
{
    private const string TODAY = '2026-11-14';

    /** @var array<string, list<int>> */
    private array $deleted = [];
    private InMemoryState $state;

    protected function setUp(): void
    {
        $this->state = new InMemoryState();
    }

    private static function orders(?array $existing, ?int $maxId): ExistingOrders
    {
        return new class ($existing, $maxId) implements ExistingOrders {
            public function __construct(private ?array $existing, private ?int $max)
            {
            }

            public function among(array $orderIds): ?array
            {
                return $this->existing;
            }

            public function maxId(): ?int
            {
                return $this->max;
            }
        };
    }

    /** Знімки замовлень 10, 11, 40. */
    private function factory(): EntityFactory
    {
        $entity = function (string $class, array $list) {
            $e = $this->createStub($class);
            $e->method('noLimit')->willReturnSelf();
            $e->method('cols')->willReturnSelf();
            $e->method('find')->willReturnCallback(static fn(array $filter = []): array => isset($filter['order_id'])
                ? array_values(array_map(static fn(array $r): int => $r['id'], array_filter($list, static fn(array $r): bool => in_array($r['order_id'], $filter['order_id'], true))))
                : array_map(static fn(array $r): object => (object)$r, $list));
            $e->method('delete')->willReturnCallback(function ($ids) use ($class): bool {
                $this->deleted[$class] = array_values((array)$ids);

                return true;
            });

            return $e;
        };
        $delivery = $entity(OrderDeliveryEntity::class, [['id' => 5, 'order_id' => 10], ['id' => 6, 'order_id' => 11], ['id' => 7, 'order_id' => 40]]);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($delivery);

        return $factory;
    }

    private function tick(?array $existing, ?int $maxId, string $today = self::TODAY): void
    {
        new OrderRowsCleanup($this->factory(), $this->state, self::orders($existing, $maxId), static fn(): string => $today)->tick();
    }

    public function testDeletedOrdersTakeTheirRows(): void
    {
        new OrderRowsCleanup($this->factory(), $this->state, self::orders(null, null))->forOrders(['10', 11]);

        self::assertSame([5, 6], $this->deleted[OrderDeliveryEntity::class]);
    }

    public function testHookedToTheOrderDelete(): void
    {
        $source = (string)file_get_contents((new \ReflectionClass(Init::class))->getFileName());

        self::assertStringContainsString("[BackendOrdersHelper::class, 'delete'], [BackendExtender::class, 'deleteOrders']", $source);
    }

    public function testNothingToDelete(): void
    {
        $factory = $this->createMock(EntityFactory::class);
        $factory->expects(self::never())->method('get');

        new OrderRowsCleanup($factory, $this->state, self::orders(null, null))->forOrders([]);
    }

    /** Замовлення, видалені, поки модуль був вимкнений: раз на день їхні рядки прибираються. */
    public function testRowsWithoutAnOrderGoOnceADay(): void
    {
        $this->tick([10 => true, 40 => true], 50);

        self::assertSame([6], $this->deleted[OrderDeliveryEntity::class]);
        self::assertSame(self::TODAY, $this->state->get('cleanup_day'));
        $this->deleted = [];
        $this->tick([10 => true, 40 => true], 50);
        self::assertSame([], $this->deleted, 'once a day');
        $this->tick([10 => true, 40 => true], 50, '2026-11-15');
        self::assertSame([6], $this->deleted[OrderDeliveryEntity::class], 'the next day again');
    }

    public function testFailedLookupKeepsEveryRowAndRetries(): void
    {
        $this->tick(null, 50);

        self::assertSame([], $this->deleted);
        self::assertNull($this->state->get('cleanup_day'), 'the next tick tries again');
    }

    public function testEmptyLookupKeepsEveryRow(): void
    {
        $this->tick([], 50);

        self::assertSame([], $this->deleted);
    }

    public function testFailedMaxIdKeepsEveryRow(): void
    {
        $this->tick([10 => true], null);

        self::assertSame([], $this->deleted);
        self::assertNull($this->state->get('cleanup_day'));
    }

    /** Замовлення з номером, більшим за найбільший видимий, ще не видно, а не видалено. */
    public function testOrderAboveTheMaxIdIsKept(): void
    {
        $this->tick([10 => true], 11);

        self::assertSame([6], $this->deleted[OrderDeliveryEntity::class]);
    }
}
