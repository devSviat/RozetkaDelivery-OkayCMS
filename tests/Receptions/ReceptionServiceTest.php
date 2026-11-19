<?php

namespace Modules\Sviat\RozetkaDelivery\Receptions;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/ArraySettings.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';
require_once __DIR__ . '/../Support/InMemoryReceptions.php';

use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryParcelRows;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryReceptions;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionService;
use PHPUnit\Framework\TestCase;

/** `POST /api/reception/create {data:{track_nums, department}}` → data{reception_id, rejected_tracks[{track_id, reason}]}. */
class ReceptionServiceTest extends TestCase
{
    private const string SENDER = 'aaaaaaaa-0000-4000-8000-000000000009';

    private ApiWorld $world;
    private InMemoryParcelRows $parcels;
    private InMemoryReceptions $receptions;

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
        $this->world = new ApiWorld();
        $this->parcels = new InMemoryParcelRows();
        $this->receptions = new InMemoryReceptions();
    }

    private function service(string $sender = self::SENDER): ReceptionService
    {
        return new ReceptionService(
            $this->world->client(),
            $this->parcels,
            $this->receptions,
            new ArraySettings([ShippingConfig::SENDER_POINT_ID => $sender]),
            new ParcelIssues(),
            $this->world->logger,
            $this->world->clock(),
        );
    }

    /** @return list<array{method: string, path: string, body: mixed}> */
    private function calls(): array
    {
        return array_map(static fn($r): array => [
            'method' => $r->method,
            'path' => (string)parse_url($r->url, PHP_URL_PATH),
            'body' => $r->body === null ? null : json_decode($r->body, true),
        ], $this->world->http->requests());
    }

    public function testCreateLinksAcceptedTracks(): void
    {
        $this->parcels->created(1, '101123456781');
        $this->parcels->created(2, '101123456782');
        $this->parcels->created(3, '101123456783', ['status_group' => 'in_transit']);
        $this->parcels->created(4, '101123456784', ['reception_id' => 77]);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['reception_id' => 555, 'rejected_tracks' => []]]);

        $result = $this->service()->create([1, 2, 3, 4, 5]);

        self::assertSame(555, $result['reception_id']);
        self::assertSame(['data' => ['track_nums' => ['101123456781', '101123456782'], 'department' => self::SENDER]], $this->calls()[0]['body']);
        self::assertSame('/api/reception/create', $this->calls()[0]['path']);
        self::assertSame(555, $this->parcels->forOrder(1)->reception_id);
        self::assertSame(555, $this->parcels->forOrder(2)->reception_id);
        self::assertSame(77, $this->parcels->forOrder(4)->reception_id);
        self::assertSame(['order_id' => 3, 'key' => 'sviat__rozetka__reception_skip_not_created'], $result['skipped'][0]);
        self::assertSame([4, 5], array_column(array_slice($result['skipped'], 1), 'order_id'));
        $saved = $this->receptions->get(555);
        self::assertSame('new', $saved->status);
        self::assertSame(2, $saved->tracks_amount);
        self::assertSame(self::SENDER, $saved->department_id);
    }

    public function testRejectedShownWithReason(): void
    {
        $this->parcels->created(1, '101123456781');
        $this->parcels->created(2, '101123456782');
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['reception_id' => 555, 'rejected_tracks' => [
            ['track_id' => '101123456782', 'reason' => '<b>Вже в іншому реєстрі</b>'],
        ]]]);

        $result = $this->service()->create([1, 2]);

        self::assertSame([['track_id' => '101123456782', 'reason' => '<b>Вже в іншому реєстрі</b>']], $result['rejected']);
        self::assertSame(555, $this->parcels->forOrder(1)->reception_id);
        self::assertNull($this->parcels->forOrder(2)->reception_id);
        self::assertSame(1, $this->receptions->get(555)->tracks_amount);
    }

    public function testNothingEligibleSendsNothing(): void
    {
        $this->parcels->created(3, '101123456783', ['status_group' => 'arrived']);

        $result = $this->service()->create([3]);

        self::assertNull($result['reception_id']);
        self::assertSame([], $this->world->http->requests());
        self::assertSame('sviat__rozetka__reception_err_none', $this->service()->create([])['error']);
        $this->parcels->created(1, '101123456781');
        self::assertSame('sviat__rozetka__reception_err_sender', $this->service('')->create([1])['error']);
        self::assertSame([], $this->world->http->requests());
    }

    public function testCreateFromReady(): void
    {
        $this->parcels->created(1, '101123456781');
        $this->parcels->created(2, '101123456782', ['status_group' => 'accepted']);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['reception_id' => 556, 'rejected_tracks' => []]]);

        $result = $this->service()->createFromReady();

        self::assertSame(556, $result['reception_id']);
        self::assertSame(['101123456781'], $this->calls()[0]['body']['data']['track_nums']);
    }

    public function testDeletedOnlyNewOrPrinted(): void
    {
        $this->receptions->save(555, ['status' => 'printed', 'created_at' => '2027-01-15 09:00:00']);
        $this->parcels->created(1, '101123456781', ['reception_id' => 555]);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['id' => 555, 'status' => ['id' => 'printed']]]);
        $this->world->http->json(200, ['statusCode' => 0]);

        $result = $this->service()->delete(555);

        self::assertTrue($result['ok']);
        self::assertSame(['GET /api/reception/555', 'DELETE /api/reception/555'], array_map(static fn(array $c): string => $c['method'] . ' ' . $c['path'], $this->calls()));
        self::assertNull($this->parcels->forOrder(1)->reception_id);
        self::assertNull($this->receptions->get(555));

        $this->receptions->save(556, ['status' => 'new', 'created_at' => '2027-01-15 09:00:00']);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['id' => 556, 'status' => ['id' => 'accepting']]]);
        $refused = $this->service()->delete(556);
        self::assertFalse($refused['ok']);
        self::assertSame('sviat__rozetka__reception_err_not_deletable', $refused['error']);
        self::assertCount(3, $this->world->http->requests(), 'no DELETE for a handed registry');
        self::assertSame('accepting', $this->receptions->get(556)->status);
    }

    public function testRefreshUpdatesStatus(): void
    {
        $this->receptions->save(555, ['status' => 'new', 'tracks_amount' => 2, 'created_at' => '2027-01-15 09:00:00']);
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['id' => 555, 'status' => ['id' => 'accepted', 'name' => 'Закритий'], 'tracks' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]]]);

        self::assertTrue($this->service()->refresh(555)['ok']);
        self::assertSame('accepted', $this->receptions->get(555)->status);
        self::assertSame(3, $this->receptions->get(555)->tracks_amount);
        self::assertNotNull($this->receptions->get(555)->checked_at);
    }

    /** Реєстр видалили в кабінеті: локальний рядок не повинен застрягти назавжди. */
    public function testDeleteGoneReceptionClearsLocal(): void
    {
        $this->receptions->save(555, ['status' => 'new', 'created_at' => '2027-01-15 09:00:00']);
        $this->parcels->created(1, '101123456781', ['reception_id' => 555]);
        $this->world->http->json(404, ['statusCode' => 404, 'message' => 'Not found']);

        self::assertTrue($this->service()->delete(555)['ok']);
        self::assertNull($this->receptions->get(555));
        self::assertNull($this->parcels->forOrder(1)->reception_id);
        self::assertCount(1, $this->world->http->requests(), 'nothing to DELETE');
    }
}
