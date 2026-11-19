<?php

namespace Modules\Sviat\RozetkaDelivery\Dictionaries;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/DepartmentFixtures.php';
require_once __DIR__ . '/../Support/InMemoryDictionaryStore.php';

use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\DepartmentFixtures;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryDictionaryStore;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryMutex;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\HttpResponse;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\TransportException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RequestBudget;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryRunner;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\DictionaryStatus;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\StatusDictionary;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Store\DictionaryStore;
use PHPUnit\Framework\TestCase;

class DictionaryRunnerTest extends TestCase
{
    private const int DAY = 86400;

    private ApiWorld $world;
    private InMemoryDictionaryStore $store;
    private InMemoryMutex $mutex;

    protected function setUp(): void
    {
        $this->world = new ApiWorld();
        $this->store = new InMemoryDictionaryStore();
        $this->mutex = new InMemoryMutex();
    }

    private function runner(?RozetkaClient $client = null): DictionaryRunner
    {
        return new DictionaryRunner(
            $client ?? $this->world->client($this->world->credentials('')),
            $this->store,
            new StatusDictionary($this->world->state),
            $this->world->state,
            $this->mutex,
            $this->world->clock(),
            function (): void {
                $this->world->now++;
            },
        );
    }

    /**
     * Сторінки по $perPage вигаданих точок; кожна друга — у власному місті, кожна третя — лише прийом.
     *
     * @return list<array<string, mixed>>
     */
    private static function points(int $count, int $from = 0): array
    {
        $points = [];
        for ($i = $from; $i < $from + $count; $i++) {
            $points[] = DepartmentFixtures::point(0, [
                'id' => sprintf('0a0b0c0d-2222-4000-8000-%012d', $i),
                'name' => sprintf('№%05d, м. Тестове, вул. Вигадана, %d', 10000 + $i, $i),
                'location' => ['city_id' => sprintf('c1c1c1c1-2222-4000-8000-%012d', intdiv($i, 2)), 'city_name' => 'м. Місто ' . intdiv($i, 2), 'street_name' => 'вул. Вигадана', 'house' => (string)$i],
                'can_give_out_tracks' => $i % 3 !== 0,
            ]);
        }

        return $points;
    }

    /** @param list<array<string, mixed>> $points */
    private function pages(array $points, ?int $total = null, int $perPage = 100): void
    {
        $chunks = array_chunk($points, $perPage) ?: [[]];
        foreach ($chunks as $i => $chunk) {
            $this->world->http->json(200, ['statusCode' => 0, 'data' => $chunk, 'pagination' => [
                'total_count' => $total ?? count($points),
                'page_count' => count($chunks),
                'page' => $i + 1,
                'limit' => $perPage,
            ]]);
        }
    }

    private function statuses(): void
    {
        $this->world->http->push(new HttpResponse(200, (string)file_get_contents(__DIR__ . '/../Support/fixtures/track-status.json')));
    }

    public function testFullSweepStoresPointsAndCities(): void
    {
        $this->pages(DepartmentFixtures::page()['data']);
        $this->statuses();

        $status = $this->runner()->tick();

        self::assertSame(DictionaryStatus::OK, $status->lastRun['status']);
        self::assertSame(['GET department?page=1&limit=100', 'GET track-status'], array_map(
            static fn($r): string => $r->method . ' ' . substr($r->url, strlen('https://rz-delivery.rozetka.ua/api/')),
            $this->world->http->requests(),
        ));
        foreach ($this->world->http->requests() as $request) {
            self::assertArrayNotHasKey('Authorization', $request->headers);
        }
        self::assertCount(3, $this->store->tables[DictionaryStore::POINTS]);
        self::assertCount(2, $this->store->tables[DictionaryStore::CITIES]);
        self::assertSame('Тестове', $this->store->tables[DictionaryStore::CITIES]['c1c1c1c1-0000-4000-8000-000000000001']['name_ua']);
        self::assertSame(['added' => 3, 'changed' => 0, 'deleted' => 0, 'unchanged' => 0], $status->lastRun['counts'][DictionaryStore::POINTS]);
        self::assertSame(500, new StatusDictionary($this->world->state)->groupOf(40040));
    }

    public function testElevenPagesAreWalked(): void
    {
        $this->pages(self::points(1006));
        $this->statuses();

        $status = $this->runner()->tick();

        self::assertSame(DictionaryStatus::OK, $status->lastRun['status']);
        self::assertCount(12, $this->world->http->requests());
        self::assertStringEndsWith('department?page=11&limit=100', $this->world->http->requests()[10]->url);
        self::assertCount(1006, $this->store->tables[DictionaryStore::POINTS]);
    }

    public function testTruncatedSweepKeepsPoints(): void
    {
        $this->pages(self::points(1006));
        $this->statuses();
        $this->runner()->tick();
        $before = $this->store->tables;
        $writes = $this->store->writes;

        $this->world->now += self::DAY;
        $this->pages(self::points(900), 1006);
        $this->statuses();
        $status = $this->runner()->tick();

        self::assertSame(DictionaryStatus::PARTIAL, $status->lastRun['status']);
        self::assertSame($before, $this->store->tables);
        self::assertSame($writes, $this->store->writes, 'nothing written, nothing deleted');
    }

    public function testHalvedSweepKeepsPoints(): void
    {
        $this->pages(self::points(1006));
        $this->statuses();
        $this->runner()->tick();
        $before = $this->store->tables;

        $this->world->now += self::DAY;
        $this->pages(self::points(400));
        $this->statuses();
        $status = $this->runner()->tick();

        self::assertSame(DictionaryStatus::PARTIAL, $status->lastRun['status']);
        self::assertSame($before, $this->store->tables);
    }

    /** Повна пагінація, але Rozetka змінила формат і жодна точка не читається: збережене не видаляється. */
    public function testUnreadablePointsKeepStored(): void
    {
        $this->pages(self::points(10));
        $this->statuses();
        $this->runner()->tick();
        $before = $this->store->tables;

        $this->world->now += self::DAY;
        $this->pages(array_map(static fn(array $p): array => ['location' => ['city' => 'x']] + $p, self::points(10)));
        $this->statuses();
        $status = $this->runner()->tick();

        self::assertSame(DictionaryStatus::PARTIAL, $status->lastRun['status']);
        self::assertSame('unparsed', $status->lastRun['reason']);
        self::assertSame($before, $this->store->tables);
    }

    public function testEmptyFirstSweepIsNotOk(): void
    {
        $this->pages([]);
        $this->statuses();

        self::assertSame(DictionaryStatus::PARTIAL, $this->runner()->tick()->lastRun['status']);
    }

    public function testMissingPointRemovedOnlyAfterFullSweep(): void
    {
        $this->pages(self::points(10));
        $this->statuses();
        $this->runner()->tick();

        $this->world->now += self::DAY;
        $this->pages(self::points(9), 10);
        $this->statuses();
        $this->runner()->tick();
        self::assertCount(10, $this->store->tables[DictionaryStore::POINTS], 'truncated run deletes nothing');

        $this->world->now += self::DAY;
        $this->pages(self::points(9));
        $this->statuses();
        $status = $this->runner()->tick();
        self::assertSame(DictionaryStatus::OK, $status->lastRun['status']);
        self::assertCount(9, $this->store->tables[DictionaryStore::POINTS]);
        self::assertArrayNotHasKey(sprintf('0a0b0c0d-2222-4000-8000-%012d', 9), $this->store->tables[DictionaryStore::POINTS]);
        self::assertSame(1, $status->lastRun['counts'][DictionaryStore::POINTS]['deleted']);
    }

    public function testSecondTickWhileRunningDoesNothing(): void
    {
        $this->mutex->held['dict'] = true;

        $status = $this->runner()->tick();

        self::assertNull($status->lastRun);
        self::assertSame([], $this->world->http->requests());
    }

    public function testOncePerDayUnlessForced(): void
    {
        $this->pages(DepartmentFixtures::page()['data']);
        $this->statuses();
        $this->runner()->tick();

        $this->world->now += self::DAY - 10;
        $this->runner()->tick();
        self::assertCount(2, $this->world->http->requests());

        $this->pages(DepartmentFixtures::page()['data']);
        $this->statuses();
        self::assertSame(DictionaryStatus::OK, $this->runner()->tick(true)->lastRun['status']);
        self::assertCount(4, $this->world->http->requests());

        DictionaryStatus::request($this->world->state, $this->world->now);
        $this->pages(DepartmentFixtures::page()['data']);
        $this->statuses();
        $this->runner()->tick();
        self::assertCount(6, $this->world->http->requests(), 'the admin button is served by the next tick');

        $this->world->now += self::DAY;
        $this->pages(DepartmentFixtures::page()['data']);
        $this->statuses();
        $this->runner()->tick();
        self::assertCount(8, $this->world->http->requests());
    }

    public function testGiveOutOnlyCounted(): void
    {
        $this->pages(self::points(6));
        $this->statuses();

        $this->runner()->tick();

        $cities = $this->store->tables[DictionaryStore::CITIES];
        self::assertSame(1, $cities[sprintf('c1c1c1c1-2222-4000-8000-%012d', 0)]['points'], 'point 0 only receives');
        self::assertSame(1, $cities[sprintf('c1c1c1c1-2222-4000-8000-%012d', 1)]['points'], 'point 3 only receives');
        self::assertSame(2, $cities[sprintf('c1c1c1c1-2222-4000-8000-%012d', 2)]['points']);
        self::assertCount(6, $this->store->tables[DictionaryStore::POINTS], 'receive-only points stay for the sender choice');
    }

    public function testPrepayMismatchRecorded(): void
    {
        $points = DepartmentFixtures::page()['data'];
        $points[1]['name'] = 'Партнер без позначки';
        $extra = [];
        for ($i = 0; $i < 6; $i++) {
            $extra[] = DepartmentFixtures::point(0, ['id' => "0a0b0c0d-3333-4000-8000-00000000000{$i}", 'name' => "Точка {$i} (тільки передоплата)"]);
        }
        $extra[] = DepartmentFixtures::point(0, ['id' => '0a0b0c0d-4444-4000-8000-000000000001', 'department_type' => ['id' => 21, 'name' => 'Нова']]);
        $extra[] = DepartmentFixtures::point(0, ['id' => '0a0b0c0d-4444-4000-8000-000000000002', 'limitations' => ['weight' => 15000]]);
        $this->pages([...$points, ...$extra]);
        $this->statuses();

        $this->runner()->tick();

        $mismatch = json_decode((string)$this->world->state->get('prepay_mismatch'), true);
        self::assertSame(7, $mismatch['count']);
        self::assertCount(5, $mismatch['names']);
        self::assertSame('Партнер без позначки', $mismatch['names'][0]);
        self::assertSame(['21' => 1], json_decode((string)$this->world->state->get('unknown_types'), true));
        self::assertSame('1', $this->world->state->get('limit_units_suspect'));
        self::assertSame(1, $this->store->tables[DictionaryStore::POINTS]['0a0b0c0d-1111-4000-8000-000000000002']['prepay_only'], 'type 14 alone still means prepay only');
    }

    public function testRateLimitWaitsAndRetries(): void
    {
        $budget = new RequestBudget($this->world->state, new InMemoryMutex(), $this->world->clock());
        $credentials = $this->world->credentials('');
        $client = new RozetkaClient($this->world->http, $this->world->tokens($credentials), $budget, $credentials, $this->world->logger);
        $this->pages(self::points(700));
        $this->statuses();

        $status = $this->runner($client)->tick();

        self::assertSame(DictionaryStatus::OK, $status->lastRun['status']);
        self::assertCount(8, $this->world->http->requests());
        self::assertGreaterThan(ApiWorld::START, $this->world->now, 'waited for the next second');
    }

    public function testUnreachableIsFailedAndKeepsCache(): void
    {
        $this->pages(self::points(3));
        $this->statuses();
        $this->runner()->tick();
        $before = $this->store->tables;

        $this->world->now += self::DAY;
        $this->world->http->push(new TransportException('timeout'));
        $status = $this->runner()->tick();

        self::assertSame(DictionaryStatus::FAILED, $status->lastRun['status']);
        self::assertSame('unreachable', $status->lastRun['error']);
        self::assertSame($before, $this->store->tables);
    }

    public function testStatusDictionaryFailureMakesRunPartial(): void
    {
        $this->pages(self::points(3));
        $this->world->http->json(500, ['statusCode' => 500]);

        $status = $this->runner()->tick();

        self::assertSame(DictionaryStatus::PARTIAL, $status->lastRun['status']);
        self::assertSame('server_error', $status->lastRun['statuses']);
        self::assertCount(3, $this->store->tables[DictionaryStore::POINTS]);
    }
}
