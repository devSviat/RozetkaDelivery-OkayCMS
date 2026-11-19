<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';

use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelLookup;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PendingParcel;
use PHPUnit\Framework\TestCase;

/** `GET /api/track?visible_id&created_date_start&created_date_end&is_deleted=false` — data: список ЕН з `id`, `visible_id`, `created_at`. */
class ParcelLookupTest extends TestCase
{
    private const int ORDER = 77;

    private ApiWorld $world;
    private InMemoryParcelRows $rows;

    protected function setUp(): void
    {
        $this->world = new ApiWorld();
        $this->rows = new InMemoryParcelRows();
        date_default_timezone_set('UTC');
    }

    private function lookup(): ParcelLookup
    {
        return new ParcelLookup($this->world->client(), $this->rows, new ParcelIssues(), $this->world->logger, $this->world->clock());
    }

    private function uncertain(string $reservedAt = '2027-01-15 10:00:00'): string
    {
        $visibleId = (string)$this->rows->reserve(self::ORDER, $reservedAt);
        $this->rows->markUncertain(self::ORDER);

        return $visibleId;
    }

    private static function item(string $id, string $visibleId, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'visible_id' => $visibleId, 'created_at' => '2027-01-15 12:00:30', 'cost' => 1000, 'is_deleted' => false,
            'delivery_payer' => 'receiver', 'shipping_cost' => 49, 'insurance_cost' => 1000,
            'recipient' => ['last_name' => 'Тестова', 'first_name' => 'Олена', 'phone' => '380501112233', 'department' => ['id' => 'aaaaaaaa-0000-4000-8000-000000000001']],
        ];
    }

    private function answer(array ...$items): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => $items, 'pagination' => ['page' => 1, 'limit' => 20, 'total' => count($items)]]);
    }

    /** @return array<string, string> */
    private function query(): array
    {
        parse_str((string)parse_url($this->world->http->requests()[0]->url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function testOneMatchAttached(): void
    {
        $visibleId = $this->uncertain();
        $this->answer(self::item('101123456789', $visibleId));

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertTrue($outcome->ok);
        self::assertSame('101123456789', $outcome->trackId);
        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame(PendingParcel::CREATED, $row->state);
        self::assertSame('101123456789', $row->track_id);
        self::assertSame(1000.0, $row->cod_amount);
        self::assertSame($visibleId, $this->query()['visible_id']);
        self::assertSame('false', $this->query()['is_deleted']);
        self::assertCount(1, $this->world->http->requests());
    }

    public function testManyMatchesNeedChoice(): void
    {
        $visibleId = $this->uncertain();
        // API фільтрує `visible_id` частковим збігом так само, як `id`: «77-1» знаходить і «77-11».
        $this->answer(self::item('101123456789', $visibleId), self::item('101123456790', $visibleId), self::item('101123456791', $visibleId . '1'));

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame(['101123456789', '101123456790'], array_column($outcome->candidates, 'track_id'));
        self::assertSame('Тестова Олена', $outcome->candidates[0]['receiver']);
        self::assertStringNotContainsString('380501112233', json_encode($outcome->candidates));
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state, 'the manager decides which one is real');

        $this->answer(self::item('101123456789', $visibleId), self::item('101123456790', $visibleId));
        $chosen = $this->lookup()->choose(self::ORDER, '101123456790');
        self::assertTrue($chosen->ok);
        self::assertSame('101123456790', $this->rows->forOrder(self::ORDER)->track_id);
    }

    public function testChosenGoneRefused(): void
    {
        $visibleId = $this->uncertain();
        $this->answer(self::item('101123456789', $visibleId));

        $outcome = $this->lookup()->choose(self::ORDER, '101123456790');

        self::assertSame('sviat__rozetka__parcel_err_lookup_gone', $outcome->issues[0]->text);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testNoneKeepsRowUntilManager(): void
    {
        $this->uncertain();
        $this->answer();

        $outcome = $this->lookup()->find(self::ORDER);

        self::assertSame('sviat__rozetka__parcel_err_lookup_none', $outcome->issues[0]->text);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state, 'only the manager button frees it');
    }

    public function testDeletedAndForeignSkipped(): void
    {
        $visibleId = $this->uncertain();
        $this->rows->created(5, '101123456790');
        $this->answer(self::item('101123456789', $visibleId, ['is_deleted' => true]), self::item('101123456790', $visibleId));

        self::assertSame('sviat__rozetka__parcel_err_lookup_none', $this->lookup()->find(self::ORDER)->issues[0]->text);
    }

    public function testDatesInKyiv(): void
    {
        // 23:30 UTC 15 січня — уже 16 січня за Києвом (UTC+2 узимку).
        $this->uncertain('2027-01-15 23:30:00');
        $this->answer();

        $this->lookup()->find(self::ORDER);

        self::assertSame('2027-01-16', $this->query()['created_date_start']);
        self::assertSame('2027-01-17', $this->query()['created_date_end']);
    }

    public function testFreshReserveNotLookedUp(): void
    {
        $this->rows->reserve(self::ORDER, date('Y-m-d H:i:s', $this->world->now - 10));

        self::assertSame('sviat__rozetka__parcel_err_pending', $this->lookup()->find(self::ORDER)->issues[0]->text);
        self::assertSame([], $this->world->http->requests());
    }
}
