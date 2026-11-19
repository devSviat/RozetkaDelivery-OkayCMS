<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/DraftFixtures.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';
require_once __DIR__ . '/ParcelServiceTest.php';

use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\DraftFixtures as F;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\MassOrders;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\MassParcels;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDraft;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelService;
use PHPUnit\Framework\TestCase;

class MassParcelsTest extends TestCase
{
    private ApiWorld $world;
    private InMemoryParcelRows $rows;
    /** @var array<int, array<string, mixed>> параметри DraftFixtures::draft за замовленням */
    private array $orders = [];

    protected function setUp(): void
    {
        $this->world = new ApiWorld();
        $this->rows = new InMemoryParcelRows();
    }

    private function mass(): MassParcels
    {
        $source = new class ($this->orders) implements MassOrders {
            public function __construct(private readonly array $orders)
            {
            }

            public function isOurs(int $orderId): bool
            {
                return isset($this->orders[$orderId]);
            }

            public function draft(int $orderId): ParcelDraft|string
            {
                return F::draft(['order' => F::order(['id' => $orderId] + ($this->orders[$orderId]['order'] ?? []))] + $this->orders[$orderId] + ['mass' => true]);
            }
        };

        return new MassParcels(
            new ParcelService($this->world->client(), $this->rows, new ParcelIssues(), $this->world->logger, $this->world->clock()),
            $this->rows,
            $source,
            $this->world->logger,
        );
    }

    public function testMassSkipsUnpaid(): void
    {
        $this->orders = [10 => ['order' => ['paid' => 0]], 11 => ['order' => ['paid' => 1]]];
        $this->world->http->json(201, ParcelServiceTest::created());

        $report = $this->mass()->run(['10', '11']);

        self::assertSame(['orderId' => 10, 'kind' => 'skipped', 'key' => 'sviat__rozetka__parcel_err_unpaid', 'value' => null], $report->rows[0]);
        self::assertSame('created', $report->rows[1]['kind']);
        self::assertCount(1, $this->world->http->requests(), 'the unpaid order never reaches Rozetka');
        self::assertNull($this->rows->forOrder(10));
    }

    public function testMassSkipsPrepayOnlyCod(): void
    {
        $this->orders = [10 => ['cod' => true, 'point' => F::point(['kind' => 'partner', 'type_id' => 14, 'prepay_only' => 1])]];

        $report = $this->mass()->run([10]);

        self::assertSame('sviat__rozetka__parcel_err_cod_prepay_only', $report->rows[0]['key']);
        self::assertSame([], $this->world->http->requests());
    }

    public function testMaxTwenty(): void
    {
        $ids = range(1, 25);
        foreach ($ids as $id) {
            $this->orders[$id] = ['order' => ['paid' => 1]];
        }
        foreach (range(1, 20) as $i) {
            $this->world->http->json(201, ParcelServiceTest::created(['track_id' => (string)(101123456000 + $i)]));
        }

        $report = $this->mass()->run($ids);

        self::assertSame(20, $report->processed());
        self::assertSame(20, $report->created());
        self::assertSame(5, $report->untouched);
        self::assertCount(20, $this->world->http->requests());
    }

    public function testForeignAndExistingSkipped(): void
    {
        $this->orders = [10 => ['order' => ['paid' => 1]]];
        $this->rows->created(10, '101123456789');

        $report = $this->mass()->run([10, 99, 'x', -1]);

        self::assertSame(['sviat__rozetka__mass_has_parcel', 'sviat__rozetka__parcel_err_not_own'], array_column($report->rows, 'key'));
        self::assertSame('101123456789', $report->rows[0]['value']);
    }
}
