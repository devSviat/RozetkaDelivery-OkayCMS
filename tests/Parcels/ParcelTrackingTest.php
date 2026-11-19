<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';
require_once __DIR__ . '/ParcelStatusTest.php';
require_once __DIR__ . '/TrackingAnswerTest.php';
require_once __DIR__ . '/../Support/RecordingInit.php';
require_once __DIR__ . '/../Support/InMemoryReceptions.php';
require_once __DIR__ . '/../Support/ArraySettings.php';

use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryMutex;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryParcelRows;
use Modules\Sviat\RozetkaDelivery\Support\RecordingInit;
use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryReceptions;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionService;
use Okay\Core\Scheduler\Schedule;
use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelHistory;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelTracking;
use PHPUnit\Framework\TestCase;

class ParcelTrackingTest extends TestCase
{
    private ApiWorld $world;
    private InMemoryParcelRows $rows;
    private InMemoryMutex $mutex;
    private InMemoryReceptions $receptions;

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
        $this->world = new ApiWorld();
        $this->rows = new InMemoryParcelRows();
        $this->mutex = new InMemoryMutex();
        $this->receptions = new InMemoryReceptions();
    }

    private function receptionService(): ReceptionService
    {
        return new ReceptionService($this->world->client(), $this->rows, $this->receptions, new ArraySettings(), new ParcelIssues(), $this->world->logger, $this->world->clock());
    }

    private function tracking(): ParcelTracking
    {
        return new ParcelTracking(
            $this->world->client(),
            $this->rows,
            ParcelStatusTest::dictionary(),
            $this->mutex,
            $this->world->flags,
            $this->world->credentials(),
            $this->world->state,
            new ParcelIssues(),
            $this->world->logger,
            $this->world->clock(),
            $this->receptions,
            $this->receptionService(),
        );
    }

    private static function track(int $i): string
    {
        return (string)(101123456000 + $i);
    }

    /** @param list<array> $items */
    private function statusAnswer(array $items): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => $items]);
    }

    private function details(array $data = []): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => $data + [
            'id' => self::track(1), 'has_paid' => true, 'payment_fee' => 30, 'expiration_date' => '2027-01-26 20:00:00', 'delivery_date' => '2027-01-16 10:00:00',
        ]]);
    }

    /** @return list<string> */
    private function calls(): array
    {
        return array_map(static fn($r): string => $r->method . ' ' . urldecode((string)preg_replace('~^https://[^/]+~', '', $r->url)), $this->world->http->requests());
    }

    public function testBatchesOfTwenty(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            $this->rows->created($i, self::track($i));
        }
        foreach ([[1, 20], [21, 40], [41, 45]] as [$from, $to]) {
            $this->statusAnswer(array_map(static fn(int $i): array => TrackingAnswerTest::item(self::track($i), 10030), range($from, $to)));
        }

        $summary = $this->tracking()->tick();

        self::assertCount(3, $this->world->http->requests());
        self::assertSame(45, $summary['updated']);
        self::assertStringContainsString('/api/track/status?id[]=' . self::track(1) . '&id[]=' . self::track(2), $this->calls()[0]);
        $row = $this->rows->forOrder(7);
        self::assertSame('accepted', $row->status_group);
        self::assertSame(10030, $row->status_code);
        self::assertSame('10030', $row->status_raw);
        self::assertSame('Відправлення у відділенні', $row->event_name);
        self::assertSame('2027-01-16 07:00:00', $row->event_at);
        self::assertCount(2, ParcelHistory::decode($row->history));
        self::assertNotNull($row->checked_at);
    }

    public function testOldParcelsSkipped(): void
    {
        $this->rows->created(1, self::track(1), ['created_at' => date('Y-m-d H:i:s', $this->world->now - 61 * 86400)]);
        $this->rows->created(2, self::track(2), ['created_at' => date('Y-m-d H:i:s', $this->world->now - 59 * 86400)]);
        $this->statusAnswer([TrackingAnswerTest::item(self::track(2), 10030)]);

        $this->tracking()->tick();

        self::assertSame(['GET /api/track/status?id[]=' . self::track(2)], $this->calls());
    }

    public function testFinalNotPolled(): void
    {
        $this->rows->created(1, self::track(1), ['status_group' => 'received']);
        $this->rows->created(2, self::track(2), ['status_group' => 'returned']);
        $this->rows->reserve(3, '2027-01-15 07:00:00');

        $this->tracking()->tick();

        self::assertSame([], $this->world->http->requests());
    }

    public function testArrivedFetchesDatesOnce(): void
    {
        $this->rows->created(1, self::track(1), ['status_group' => 'in_transit']);
        $this->statusAnswer([TrackingAnswerTest::item(self::track(1), 40030)]);
        $this->details();

        $this->tracking()->tick();

        $row = $this->rows->forOrder(1);
        self::assertSame('arrived', $row->status_group);
        self::assertSame('2027-01-16 07:00:00', $row->arrived_at);
        self::assertSame('2027-01-26 18:00:00', $row->expiration_date);
        self::assertSame(['GET /api/track/status?id[]=' . self::track(1), 'GET /api/track/' . self::track(1)], $this->calls());
    }

    public function testCodReceivedFetchesHasPaidOnce(): void
    {
        $this->rows->created(1, self::track(1), ['status_group' => 'arrived', 'cod_amount' => 1000.0]);
        $this->statusAnswer([TrackingAnswerTest::item(self::track(1), 60030)]);
        $this->details();
        $this->tracking()->tick();

        $row = $this->rows->forOrder(1);
        self::assertSame('received', $row->status_group);
        self::assertSame(1, $row->has_paid);
        self::assertSame(30.0, $row->payment_fee);
        self::assertCount(2, $this->world->http->requests());

        // Отримана — фінальна: другий тік її вже не опитує.
        $this->tracking()->tick();
        self::assertCount(2, $this->world->http->requests());
    }

    public function testNoTransitionNoExtraGet(): void
    {
        $this->rows->created(1, self::track(1), ['status_group' => 'arrived', 'cod_amount' => 1000.0, 'expiration_date' => '2027-01-26 18:00:00']);
        $this->statusAnswer([TrackingAnswerTest::item(self::track(1), 40080)]);

        $this->tracking()->tick();

        self::assertSame('arrived', $this->rows->forOrder(1)->status_group);
        self::assertCount(1, $this->world->http->requests(), 'the dates are fetched once, on arrival');
    }

    public function testPartialReceivedFetchesToo(): void
    {
        $this->rows->created(1, self::track(1), ['status_group' => 'arrived', 'cod_amount' => 1000.0]);
        $this->statusAnswer([TrackingAnswerTest::item(self::track(1), 'gaveOutPartially')]);
        $this->details(['has_paid' => false]);

        $this->tracking()->tick();

        $row = $this->rows->forOrder(1);
        self::assertSame(1, $row->partial);
        self::assertSame(0, $row->has_paid);
    }

    public function testNonCodReceivedNoExtraGet(): void
    {
        $this->rows->created(1, self::track(1), ['status_group' => 'arrived', 'cod_amount' => 0.0]);
        $this->statusAnswer([TrackingAnswerTest::item(self::track(1), 60030)]);

        $this->tracking()->tick();

        self::assertSame('received', $this->rows->forOrder(1)->status_group);
        self::assertCount(1, $this->world->http->requests());
    }

    public function testExtraGetsCappedAtTen(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->rows->created($i, self::track($i), ['status_group' => 'arrived', 'cod_amount' => 500.0]);
        }
        $this->statusAnswer(array_map(static fn(int $i): array => TrackingAnswerTest::item(self::track($i), 60030), range(1, 12)));
        for ($i = 1; $i <= 10; $i++) {
            $this->details(['id' => self::track($i)]);
        }

        $summary = $this->tracking()->tick();

        self::assertCount(11, $this->world->http->requests());
        self::assertSame(10, $summary['extra']);
        // Без даних оплати рядок не переходить у фінальну групу: наступний тік повторить перехід.
        self::assertSame('arrived', $this->rows->forOrder(12)->status_group);
        self::assertNull($this->rows->forOrder(12)->has_paid);

        $this->statusAnswer([TrackingAnswerTest::item(self::track(11), 60030), TrackingAnswerTest::item(self::track(12), 60030)]);
        $this->details(['id' => self::track(11)]);
        $this->details(['id' => self::track(12)]);
        $this->tracking()->tick();
        self::assertSame(1, $this->rows->forOrder(12)->has_paid);
    }

    /** Видана ЕН з післяплатою, оплату якої Rozetka ще не підтвердила: чек «при отриманні» чекає на `has_paid`. */
    private function unpaidReceived(int $order, array $values = []): object
    {
        return $this->rows->created($order, self::track($order), $values + [
            'status_group' => 'received', 'cod_amount' => 1000.0, 'has_paid' => 0, 'partial' => 0,
            'event_at' => $this->ago(86400), 'checked_at' => $this->ago(2 * 3600),
        ]);
    }

    private function ago(int $seconds): string
    {
        return date('Y-m-d H:i:s', $this->world->now - $seconds);
    }

    public function testUnpaidCodReceivedRereadUntilPaid(): void
    {
        $this->unpaidReceived(1);
        $this->details(['has_paid' => false]);

        $this->tracking()->tick();

        self::assertSame(['GET /api/track/' . self::track(1)], $this->calls());
        self::assertSame(0, $this->rows->forOrder(1)->has_paid);
        self::assertSame(date('Y-m-d H:i:s', $this->world->now), $this->rows->forOrder(1)->checked_at);

        $this->world->now += 3600;
        $this->details();
        $summary = $this->tracking()->tick();

        self::assertSame(1, $this->rows->forOrder(1)->has_paid);
        self::assertSame(1, $summary['extra']);
        self::assertSame('received', $this->rows->forOrder(1)->status_group, 'група не змінюється');

        $this->world->now += 3600;
        $this->tracking()->tick();
        self::assertCount(2, $this->world->http->requests(), 'оплачену більше не перечитують');
    }

    /** Успішне перечитування доводить, що токен знову працює: попередження про трекінг зникає. */
    public function testUnpaidRereadClearsTrackingAuthFlag(): void
    {
        $this->world->flags->record(AuthFlags::TRACKING, RozetkaApiException::of(ApiErrorKind::BadToken, 'відмова'));
        $this->unpaidReceived(1);
        $this->details();

        $this->tracking()->tick();

        self::assertNull($this->world->flags->reason(AuthFlags::TRACKING));
    }

    public function testUnpaidRereadAtMostHourly(): void
    {
        $this->unpaidReceived(1, ['checked_at' => $this->ago(30 * 60)]);

        $this->tracking()->tick();

        self::assertSame([], $this->world->http->requests());
    }

    public function testUnpaidRereadStopsFourteenDaysAfterReceipt(): void
    {
        $this->unpaidReceived(1, ['event_at' => $this->ago(15 * 86400)]);
        $this->unpaidReceived(2, ['event_at' => $this->ago(13 * 86400), 'has_paid' => null]);
        $this->details(['id' => self::track(2)]);

        $this->tracking()->tick();

        self::assertSame(['GET /api/track/' . self::track(2)], $this->calls());
        self::assertSame(1, $this->rows->forOrder(2)->has_paid);
        self::assertSame(0, $this->rows->forOrder(1)->has_paid);
    }

    /** Чек на частково видану чи без післяплати не виставляється, тож і бюджет на них не йде. */
    public function testUnpaidRereadSkipsPartialNonCodAndPaid(): void
    {
        $this->unpaidReceived(1, ['partial' => 1]);
        $this->unpaidReceived(2, ['cod_amount' => 0.0]);
        $this->unpaidReceived(3, ['has_paid' => 1]);
        $this->unpaidReceived(4, ['status_group' => 'returned']);
        $this->rows->rows[$this->unpaidReceived(5)->id]->state = 'uncertain';

        $this->tracking()->tick();

        self::assertSame([], $this->world->http->requests());
    }

    /** Переходи мають перевагу: перечитування бере лише те, що лишилось від бюджету тіку. */
    public function testUnpaidRereadUsesOnlyLeftoverBudget(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $this->rows->created($i, self::track($i), ['status_group' => 'arrived', 'cod_amount' => 500.0]);
        }
        for ($i = 21; $i <= 23; $i++) {
            $this->unpaidReceived($i, ['checked_at' => $this->ago((30 - $i) * 3600)]);
        }
        $this->statusAnswer(array_map(static fn(int $i): array => TrackingAnswerTest::item(self::track($i), 60030), range(1, 9)));
        for ($i = 1; $i <= 10; $i++) {
            $this->details(['id' => self::track($i)]);
        }

        $summary = $this->tracking()->tick();

        self::assertSame(10, $summary['extra']);
        self::assertCount(11, $this->world->http->requests());
        self::assertSame('GET /api/track/' . self::track(21), $this->calls()[10], 'найдавніше перевірена — першою');
        self::assertSame(1, $this->rows->forOrder(21)->has_paid);
        self::assertSame(0, $this->rows->forOrder(22)->has_paid);
    }

    public function testUnpaidRereadRefusalMarksChecked(): void
    {
        $this->unpaidReceived(1);
        $this->unpaidReceived(2, ['checked_at' => $this->ago(3600)]);
        $this->world->http->json(404, ['statusCode' => 404, 'message' => 'not found']);
        $this->details(['id' => self::track(2)]);

        $this->tracking()->tick();

        self::assertCount(2, $this->world->http->requests());
        self::assertSame(0, $this->rows->forOrder(1)->has_paid);
        self::assertSame(date('Y-m-d H:i:s', $this->world->now), $this->rows->forOrder(1)->checked_at, 'черга не стоїть на ній');
        self::assertSame(1, $this->rows->forOrder(2)->has_paid);
    }

    public function testUnknownStatusKeepsGroupAndIsRemembered(): void
    {
        $this->rows->created(1, self::track(1), ['status_group' => 'in_transit']);
        $this->statusAnswer([TrackingAnswerTest::item(self::track(1), 'teleported')]);

        $this->tracking()->tick();

        $row = $this->rows->forOrder(1);
        self::assertSame('in_transit', $row->status_group);
        self::assertSame('teleported', $row->status_raw);
        self::assertContains('teleported', json_decode((string)$this->world->state->get('unknown_statuses'), true));
    }

    public function testOverlapProtected(): void
    {
        $this->rows->created(1, self::track(1));
        $this->mutex->held['track'] = true;

        $this->tracking()->tick();

        self::assertSame([], $this->world->http->requests());
    }

    public function testNoTokenNoRequests(): void
    {
        $this->rows->created(1, self::track(1));
        $tracking = new ParcelTracking(
            $this->world->client(), $this->rows, ParcelStatusTest::dictionary(), $this->mutex, $this->world->flags,
            $this->world->credentials(''), $this->world->state, new ParcelIssues(), $this->world->logger, $this->world->clock(),
            $this->receptions, $this->receptionService(),
        );

        $tracking->tick();

        self::assertSame([], $this->world->http->requests());
    }

    public function testRefreshReplacesHistory(): void
    {
        $this->rows->created(1, self::track(1), ['status_group' => 'in_transit', 'history' => '[{"code":1,"raw":"1","name":"стара","at":"2027-01-01 00:00:00"}]']);
        $this->statusAnswer([TrackingAnswerTest::item(self::track(1), 40030)]);
        $this->details();

        $outcome = $this->tracking()->refresh(1);

        self::assertTrue($outcome->ok);
        self::assertSame([10010, 40030], array_column(ParcelHistory::decode($this->rows->forOrder(1)->history), 'code'));
        self::assertSame('2027-01-26 18:00:00', $this->rows->forOrder(1)->expiration_date);
        self::assertCount(2, $this->world->http->requests());
    }

    /** Реєстр закривається, коли точка прийняла всі ЕН: тік стежить за ним, доки він не закритий, але не довше двох тижнів. */
    public function testReceptionsRefreshedUpToFive(): void
    {
        $day = 86400;
        for ($i = 1; $i <= 7; $i++) {
            $this->receptions->save(500 + $i, ['status' => 'new', 'created_at' => date('Y-m-d H:i:s', $this->world->now - $i * 3600)]);
        }
        $this->receptions->save(600, ['status' => 'accepted', 'created_at' => date('Y-m-d H:i:s', $this->world->now - 3600)]);
        $this->receptions->save(601, ['status' => 'new', 'created_at' => date('Y-m-d H:i:s', $this->world->now - 15 * $day)]);
        for ($i = 1; $i <= 5; $i++) {
            $this->world->http->json(200, ['statusCode' => 0, 'data' => ['id' => 500 + $i, 'status' => ['id' => 'accepting']]]);
        }

        $this->tracking()->tick();

        $paths = array_map(static fn($r): string => (string)parse_url($r->url, PHP_URL_PATH), $this->world->http->requests());
        self::assertCount(5, $paths);
        self::assertSame([], array_values(array_filter($paths, static fn(string $p): bool => in_array($p, ['/api/reception/600', '/api/reception/601'], true))));
        self::assertSame(5, count(array_filter($this->receptions->rows, static fn(object $r): bool => $r->status === 'accepting')));
    }

    public function testScheduledEveryFifteenMinutesWithoutOverlap(): void
    {
        $found = [];
        foreach (RecordingInit::record() as [$kind, $schedule]) {
            if ($kind !== 'schedule' || !$schedule instanceof Schedule) {
                continue;
            }
            $read = static fn(string $property): mixed => new \ReflectionProperty(Schedule::class, $property)->getValue($schedule);
            if ($read('command') === [ParcelTracking::class, 'tick']) {
                $found[] = [$read('name'), $read('time'), $read('overlap'), $read('timeout')];
            }
        }

        self::assertSame([['Rozetka: tracking', '*/15 * * * *', false, 600]], $found);
    }
}
