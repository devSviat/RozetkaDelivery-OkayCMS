<?php

namespace Modules\Sviat\RozetkaDelivery\Api;

require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';

use Modules\Sviat\RozetkaDelivery\Support\InMemoryMutex;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryState;
use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RequestBudget;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\Mutex;
use PHPUnit\Framework\TestCase;

class RequestBudgetTest extends TestCase
{
    private const int START = 1_800_000_000;

    private InMemoryState $state;
    private InMemoryMutex $mutex;
    private int $now = self::START;

    protected function setUp(): void
    {
        $this->state = new InMemoryState();
        $this->mutex = new InMemoryMutex();
        $this->now = self::START;
    }

    private function budget(?Mutex $mutex = null): RequestBudget
    {
        return new RequestBudget($this->state, $mutex ?? $this->mutex, fn(): int => $this->now);
    }

    private function take(RequestBudget $budget, string $slot, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $budget->take($slot);
        }
    }

    private function assertRefused(RequestBudget $budget, string $slot): void
    {
        try {
            $budget->take($slot);
            self::fail('expected RateLimited');
        } catch (RozetkaApiException $e) {
            self::assertSame(ApiErrorKind::RateLimited, $e->kind);
        }
    }

    public function testSixthRequestInSecondRefused(): void
    {
        $budget = $this->budget();
        $this->take($budget, RequestBudget::OTHER, 5);

        $this->assertRefused($budget, RequestBudget::OTHER);
        $this->assertRefused($budget, RequestBudget::CART);
        self::assertSame([], $this->mutex->held);
    }

    public function testThirdCartRequestRefused(): void
    {
        $budget = $this->budget();
        $this->take($budget, RequestBudget::CART, 2);

        $this->assertRefused($budget, RequestBudget::CART);
        $this->take($budget, RequestBudget::OTHER, 3);
        $this->assertRefused($budget, RequestBudget::OTHER);
    }

    public function testWindowResetsNextSecond(): void
    {
        $budget = $this->budget();
        $this->take($budget, RequestBudget::OTHER, 3);
        $this->take($budget, RequestBudget::CART, 2);
        $this->assertRefused($budget, RequestBudget::OTHER);

        $this->now = self::START + RequestBudget::WINDOW;
        $budget->take(RequestBudget::CART);
        self::assertSame(['window_start' => self::START + 1, 'all' => 1, 'cart' => 1], json_decode((string)$this->state->get('request_budget'), true));
    }

    public function testSharedAcrossInstances(): void
    {
        $this->take($this->budget(), RequestBudget::OTHER, 4);
        $this->budget()->take(RequestBudget::OTHER);

        $this->assertRefused($this->budget(), RequestBudget::OTHER);
    }

    public function testGarbageStateStartsFreshWindow(): void
    {
        $this->state->set('request_budget', '{"all":"x"');
        $this->budget()->take(RequestBudget::OTHER);

        self::assertSame(['window_start' => self::START, 'all' => 1, 'cart' => 0], json_decode((string)$this->state->get('request_budget'), true));
    }

    public function testLockWaitsOneSecond(): void
    {
        $mutex = new class implements Mutex {
            /** @var list<array{string, int}> */
            public array $acquired = [];

            public function acquire(string $name, int $waitSeconds = 0): bool
            {
                $this->acquired[] = [$name, $waitSeconds];

                return true;
            }

            public function release(string $name): void
            {
            }
        };

        $this->budget($mutex)->take(RequestBudget::OTHER);

        self::assertSame([['budget', 1]], $mutex->acquired);
    }

    public function testNoLockRefuses(): void
    {
        $this->mutex->held['budget'] = true;

        $this->assertRefused($this->budget(), RequestBudget::OTHER);
        self::assertNull($this->state->get('request_budget'));
    }
}
