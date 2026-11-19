<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/DraftFixtures.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';

use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\DraftFixtures as F;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\TransportException;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDetails;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDraft;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssue;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelService;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PendingParcel;
use PHPUnit\Framework\TestCase;

/**
 * Відповідь `POST /api/track` за схемою: data{track_id, shipping_cost, delivery_date, payment_fee, payment_fee_details{…}}.
 * `GET /api/track/{id}` — data з полями ЕН (cost, insurance_cost, delivery_payer, has_paid, recipient, last_status…).
 */
class ParcelServiceTest extends TestCase
{
    public const string TRACK = '101123456789';
    private const int ORDER = 77;

    private ApiWorld $world;
    private InMemoryParcelRows $rows;

    protected function setUp(): void
    {
        $this->world = new ApiWorld();
        $this->rows = new InMemoryParcelRows();
        date_default_timezone_set('UTC');
    }

    private function service(): ParcelService
    {
        return new ParcelService($this->world->client(), $this->rows, new ParcelIssues(), $this->world->logger, $this->world->clock());
    }

    private static function draft(): ParcelDraft
    {
        return F::draft(['cod' => true]);
    }

    public static function created(array $data = []): array
    {
        return ['statusCode' => 0, 'data' => $data + [
            'track_id' => self::TRACK,
            'shipping_cost' => 49,
            'delivery_date' => '2027-01-20 18:00:00',
            'payment_fee' => 30,
            'payment_fee_details' => ['percentage' => 1.5, 'additional' => 15, 'payment_fee_limit' => 0],
            'carrier_track_num' => null,
        ]];
    }

    /** @return list<array{method: string, path: string, body: mixed}> */
    private function requests(): array
    {
        return array_map(static fn($r): array => [
            'method' => $r->method,
            'path' => (string)parse_url($r->url, PHP_URL_PATH),
            'body' => $r->body === null ? null : json_decode($r->body, true),
        ], $this->world->http->requests());
    }

    public function testCreatedStoresAnswer(): void
    {
        $this->world->http->json(201, self::created());

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertTrue($outcome->ok, json_encode($outcome->issues));
        self::assertSame(self::TRACK, $outcome->trackId);
        $request = $this->requests()[0];
        self::assertSame(['POST', '/api/track'], [$request['method'], $request['path']]);
        self::assertSame('77-1', $request['body']['data']['visible_id']);
        self::assertSame(1000.0, (float)$request['body']['data']['cost']);
        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame(PendingParcel::CREATED, $row->state);
        self::assertSame(self::TRACK, $row->track_id);
        self::assertSame('77-1', $row->visible_id);
        self::assertSame(49.0, $row->shipping_cost);
        self::assertSame(30.0, $row->payment_fee);
        self::assertSame(1000.0, $row->cod_amount);
        self::assertSame(1000.0, $row->declared_value);
        self::assertSame('receiver', $row->delivery_payer);
        self::assertSame('setting', $row->payer_reason);
        self::assertSame(F::POINT, $row->point_id);
        // Київ узимку — UTC+2, застосунок у тесті — UTC.
        self::assertSame('2027-01-20 16:00:00', $row->delivery_date);
        self::assertSame('created', $row->status_group);
        self::assertSame(1.5, ParcelDetails::decode($row->details)['fee_percentage']);
    }

    public function testTimeoutIsUncertainAndOnePost(): void
    {
        $this->world->http->push(new TransportException('timed out'));

        $outcome = $this->service()->create(self::ORDER, self::draft());
        $again = $this->service()->create(self::ORDER, self::draft());

        self::assertFalse($outcome->ok);
        self::assertSame('sviat__rozetka__parcel_err_uncertain', $outcome->issues[0]->text);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
        self::assertSame('sviat__rozetka__parcel_err_uncertain', $again->issues[0]->text);
        self::assertCount(1, $this->world->http->requests(), 'exactly one POST: Rozetka may have created it');
    }

    public function testServerErrorIsUncertain(): void
    {
        $this->world->http->json(502, ['statusCode' => 502, 'message' => 'Bad gateway']);

        $this->service()->create(self::ORDER, self::draft());

        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testAnswerWithoutTrackIsUncertain(): void
    {
        $this->world->http->json(201, ['statusCode' => 0, 'data' => ['shipping_cost' => 49]]);

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertFalse($outcome->ok);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function test400FreesRowAndMapsField(): void
    {
        $this->world->http->json(400, ['statusCode' => 400, 'message' => 'Validation failed', 'details' => [
            ['property' => 'recipient.phone', 'constraints' => ['isPhone' => 'Невірний формат телефону']],
        ]]);

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertFalse($outcome->ok);
        self::assertEquals([new ParcelIssue('phone', 'Невірний формат телефону')], $outcome->issues);
        self::assertNull($this->rows->forOrder(self::ORDER), 'Rozetka refused: nothing exists, the order is free again');
    }

    public function testDraftErrorsSendNothing(): void
    {
        $outcome = $this->service()->create(self::ORDER, F::draft());

        self::assertFalse($outcome->ok);
        self::assertContains('sviat__rozetka__parcel_err_unpaid', array_map(static fn(ParcelIssue $i): string => $i->text, $outcome->issues));
        self::assertSame([], $this->world->http->requests());
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    public function testSecondCreateWhilePendingRefused(): void
    {
        $this->rows->reserve(self::ORDER, date('Y-m-d H:i:s', $this->world->now - 30));

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertSame('sviat__rozetka__parcel_err_pending', $outcome->issues[0]->text);
        self::assertSame([], $this->world->http->requests());
    }

    public function testOldReserveIsUncertain(): void
    {
        $this->rows->reserve(self::ORDER, date('Y-m-d H:i:s', $this->world->now - 601));

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertSame('sviat__rozetka__parcel_err_uncertain', $outcome->issues[0]->text);
        self::assertSame([], $this->world->http->requests());
    }

    public function testExistingParcelRefused(): void
    {
        $this->rows->created(self::ORDER, self::TRACK);

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertSame('sviat__rozetka__parcel_exists', $outcome->issues[0]->text);
        self::assertSame([], $this->world->http->requests());
    }

    public function testNotSavedKeepsUncertain(): void
    {
        $this->rows->failComplete = true;
        $this->world->http->json(201, self::created());

        $outcome = $this->service()->create(self::ORDER, self::draft());

        self::assertSame('sviat__rozetka__parcel_err_not_saved', $outcome->issues[0]->text);
        self::assertSame(self::TRACK, $outcome->value);
        self::assertSame(PendingParcel::UNCERTAIN, $this->rows->forOrder(self::ORDER)->state);
    }

    public function testAttachByNumber(): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => [
            'id' => self::TRACK, 'cost' => 0, 'insurance_cost' => 500, 'shipping_cost' => 49, 'delivery_payer' => 'sender',
            'has_paid' => false, 'expiration_date' => '2027-01-25 20:00:00',
            'recipient' => ['last_name' => 'Тестова', 'first_name' => 'Олена', 'phone' => ['380501112233'], 'department' => ['id' => strtoupper(F::POINT)]],
        ]]);

        $outcome = $this->service()->attach(self::ORDER, ' 101 123 456 789 ');

        self::assertTrue($outcome->ok);
        self::assertSame('/api/track/' . self::TRACK, $this->requests()[0]['path']);
        $row = $this->rows->forOrder(self::ORDER);
        self::assertSame(self::TRACK, $row->track_id);
        self::assertSame(PendingParcel::CREATED, $row->state);
        self::assertSame('sender', $row->delivery_payer);
        self::assertSame(500.0, $row->declared_value);
        self::assertSame(F::POINT, $row->point_id);
        self::assertSame('2027-01-25 18:00:00', $row->expiration_date);
        self::assertStringNotContainsString('380501112233', (string)$row->details);
    }

    public function testAttachForeignOrUsedRefused(): void
    {
        $this->world->http->json(404, ['statusCode' => 404, 'message' => 'Not found']);
        self::assertSame('sviat__rozetka__parcel_err_not_ours', $this->service()->attach(self::ORDER, self::TRACK)->issues[0]->text);

        $this->rows->created(5, self::TRACK);
        $used = $this->service()->attach(self::ORDER, self::TRACK);
        self::assertSame('sviat__rozetka__parcel_err_track_elsewhere', $used->issues[0]->text);
        self::assertSame('5', $used->value);

        self::assertSame('sviat__rozetka__parcel_err_track', $this->service()->attach(self::ORDER, 'abc')->issues[0]->text);
        self::assertCount(1, $this->world->http->requests());
    }

    public function testBodyNeverLogged(): void
    {
        $this->world->http->json(201, self::created());
        $this->service()->create(self::ORDER, self::draft());
        $this->world->http->json(400, ['statusCode' => 400, 'message' => 'Тестова 380501112233']);
        $this->service()->create(78, self::draft());

        $log = json_encode($this->world->logger->records, JSON_UNESCAPED_UNICODE);
        self::assertNotEmpty($this->world->logger->records);
        foreach (['Тестова', 'Олена', '380501112233', '0501112233', 'Відправник'] as $secret) {
            self::assertStringNotContainsString($secret, $log, $secret);
        }
    }
}
