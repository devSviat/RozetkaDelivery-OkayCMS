<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';

use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelService;
use PHPUnit\Framework\TestCase;

/**
 * `DELETE /api/track {data:[id]}` відповідає лише `{statusCode}` без поштучного результату, тож видалення
 * підтверджує наступний `GET /api/track/{id}` (`is_deleted` чи 404).
 */
class ParcelDeleteTest extends TestCase
{
    private const int ORDER = 77;
    private const string TRACK = '101123456789';

    private ApiWorld $world;
    private InMemoryParcelRows $rows;

    protected function setUp(): void
    {
        $this->world = new ApiWorld();
        $this->rows = new InMemoryParcelRows();
        $this->rows->created(self::ORDER, self::TRACK);
    }

    private function service(): ParcelService
    {
        return new ParcelService($this->world->client(), $this->rows, new ParcelIssues(), $this->world->logger, $this->world->clock());
    }

    private function track(array $data = []): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => $data + [
            'id' => self::TRACK, 'last_status' => 10010, 'is_editable' => true, 'is_deleted' => false, 'is_in_reception' => false,
        ]]);
    }

    private function deleted(): void
    {
        $this->world->http->json(200, ['statusCode' => 0]);
    }

    /** @return list<string> */
    private function calls(): array
    {
        return array_map(static fn($r): string => $r->method . ' ' . parse_url($r->url, PHP_URL_PATH), $this->world->http->requests());
    }

    public function testDeleteConfirmedRemovesRow(): void
    {
        $this->track();
        $this->deleted();
        $this->track(['is_deleted' => true]);

        $outcome = $this->service()->delete(self::ORDER);

        self::assertTrue($outcome->ok);
        self::assertNull($this->rows->forOrder(self::ORDER));
        self::assertSame(['GET /api/track/' . self::TRACK, 'DELETE /api/track', 'GET /api/track/' . self::TRACK], $this->calls());
        self::assertSame(['data' => [self::TRACK]], json_decode((string)$this->world->http->requests()[1]->body, true));
    }

    public function testNotFoundAfterDeleteConfirms(): void
    {
        $this->track();
        $this->deleted();
        $this->world->http->json(404, ['statusCode' => 404, 'message' => 'Not found']);

        self::assertTrue($this->service()->delete(self::ORDER)->ok);
        self::assertNull($this->rows->forOrder(self::ORDER));
    }

    public function testDeleteNotConfirmedKeepsRow(): void
    {
        $this->track();
        $this->deleted();
        $this->track();

        $outcome = $this->service()->delete(self::ORDER);

        self::assertFalse($outcome->ok);
        self::assertSame('sviat__rozetka__parcel_err_delete_unconfirmed', $outcome->issues[0]->text);
        self::assertNotNull($this->rows->forOrder(self::ORDER), 'a 200 without is_deleted proves nothing');
    }

    public function testNotEditableRefusedWithoutDelete(): void
    {
        $this->track(['is_editable' => false]);
        self::assertSame('sviat__rozetka__parcel_err_not_deletable', $this->service()->delete(self::ORDER)->issues[0]->text);

        $this->track(['last_status' => 10030]);
        self::assertSame('sviat__rozetka__parcel_err_not_deletable', $this->service()->delete(self::ORDER)->issues[0]->text, 'accepted by Rozetka');

        self::assertSame(['GET /api/track/' . self::TRACK, 'GET /api/track/' . self::TRACK], $this->calls());
        self::assertNotNull($this->rows->forOrder(self::ORDER));
    }

    public function testLocalGroupBeyondCreatedRefusedWithoutRequests(): void
    {
        $this->rows->forOrder(self::ORDER)->status_group = 'in_transit';

        self::assertSame('sviat__rozetka__parcel_err_not_deletable', $this->service()->delete(self::ORDER)->issues[0]->text);
        self::assertSame([], $this->calls());
    }

    public function testInReceptionRemovedFirst(): void
    {
        $this->rows->forOrder(self::ORDER)->reception_id = 555;
        $this->track(['is_in_reception' => true]);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['id' => 555, 'status' => ['id' => 'printed', 'name' => 'Надруковано']]]);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['reception_id' => 555, 'not_available_for_deletion_tracks' => []]]);
        $this->deleted();
        $this->track(['is_deleted' => true]);

        $outcome = $this->service()->delete(self::ORDER);

        self::assertTrue($outcome->ok, json_encode($outcome->issues));
        self::assertSame([
            'GET /api/track/' . self::TRACK,
            'GET /api/reception/555',
            'PATCH /api/reception/remove-tracks/555',
            'DELETE /api/track',
            'GET /api/track/' . self::TRACK,
        ], $this->calls());
        self::assertSame(['track_nums' => [self::TRACK]], json_decode((string)$this->world->http->requests()[2]->body, true));
    }

    public function testReceptionAcceptedRefuses(): void
    {
        $this->rows->forOrder(self::ORDER)->reception_id = 555;
        $this->track(['is_in_reception' => true]);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['id' => 555, 'status' => ['id' => 'accepting', 'name' => 'Приймається']]]);

        $outcome = $this->service()->delete(self::ORDER);

        self::assertSame('sviat__rozetka__parcel_err_reception_handed', $outcome->issues[0]->text);
        self::assertSame(['GET /api/track/' . self::TRACK, 'GET /api/reception/555'], $this->calls());
    }

    public function testRemovalFromReceptionRefusedStops(): void
    {
        $this->rows->forOrder(self::ORDER)->reception_id = 555;
        $this->track(['is_in_reception' => true]);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['id' => 555, 'status' => ['id' => 'new']]]);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['reception_id' => 555, 'not_available_for_deletion_tracks' => [self::TRACK]]]);

        self::assertSame('sviat__rozetka__parcel_err_reception_handed', $this->service()->delete(self::ORDER)->issues[0]->text);
        self::assertCount(3, $this->calls(), 'no DELETE');
    }

    public function testForeignReceptionRefuses(): void
    {
        $this->track(['is_in_reception' => true]);

        self::assertSame('sviat__rozetka__parcel_err_reception_foreign', $this->service()->delete(self::ORDER)->issues[0]->text);
        self::assertCount(1, $this->calls());
    }

    /** Реєстр видалили чи ЕН прибрали з нього в кабінеті: вирішує свіжий `is_in_reception`, а не локальний номер. */
    public function testStaleLocalReceptionIgnored(): void
    {
        $this->rows->forOrder(self::ORDER)->reception_id = 555;
        $this->track(['is_in_reception' => false]);
        $this->deleted();
        $this->track(['is_deleted' => true]);

        self::assertTrue($this->service()->delete(self::ORDER)->ok);
        self::assertSame(['GET /api/track/' . self::TRACK, 'DELETE /api/track', 'GET /api/track/' . self::TRACK], $this->calls());
    }

    public function testUnparseableRemoveTracksRefuses(): void
    {
        $this->rows->forOrder(self::ORDER)->reception_id = 555;
        $this->track(['is_in_reception' => true]);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['id' => 555, 'status' => ['id' => 'new']]]);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => 'ok']);

        $outcome = $this->service()->delete(self::ORDER);

        self::assertSame('sviat__rozetka__parcel_err_reception_handed', $outcome->issues[0]->text);
        self::assertSame(555, $this->rows->forOrder(self::ORDER)->reception_id, 'the shop still knows the registry');
        self::assertCount(3, $this->calls(), 'no DELETE');
    }
}
