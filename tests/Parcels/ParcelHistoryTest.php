<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelHistory;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\TrackingEvent;
use PHPUnit\Framework\TestCase;

class ParcelHistoryTest extends TestCase
{
    private static function event(int|string $status, string $at, string $name = 'Подія'): TrackingEvent
    {
        return new TrackingEvent(is_int($status) ? $status : null, (string)$status, $at, $name);
    }

    public function testNewestFiftyKept(): void
    {
        $events = [];
        for ($i = 0; $i < 60; $i++) {
            $events[] = self::event(10070, date('Y-m-d H:i:s', strtotime('2026-11-01 00:00:00') + $i * 60));
        }

        $history = ParcelHistory::decode(ParcelHistory::encode(ParcelHistory::fromEvents($events)));

        self::assertCount(ParcelHistory::MAX_EVENTS, $history);
        self::assertSame('2026-11-01 00:10:00', $history[0]['at']);
        self::assertSame('2026-11-01 00:59:00', $history[49]['at']);
    }

    public function testEntryShape(): void
    {
        self::assertSame(
            [['code' => 60030, 'raw' => '60030', 'name' => 'Видано', 'at' => '2026-11-10 12:00:00']],
            ParcelHistory::fromEvents([self::event(60030, '2026-11-10 12:00:00', 'Видано')]),
        );
        self::assertSame(
            [['code' => null, 'raw' => 'inTheWay', 'name' => 'В дорозі', 'at' => '2026-11-10 12:00:00']],
            ParcelHistory::fromEvents([self::event('inTheWay', '2026-11-10 12:00:00', 'В дорозі')]),
        );
    }

    public function testSameKeyNotAppended(): void
    {
        $history = ParcelHistory::fromEvents([self::event(10070, '2026-11-08 09:00:00'), self::event(40030, '2026-11-08 12:00:00')]);

        self::assertSame($history, ParcelHistory::append($history, self::event(40030, '2026-11-08 12:00:00', 'Інша назва')));
        self::assertCount(3, ParcelHistory::append($history, self::event(40030, '2026-11-09 07:00:00')));
    }

    public function testOlderEventLandsInOrder(): void
    {
        $history = ParcelHistory::fromEvents([self::event(10070, '2026-11-08 09:00:00'), self::event(40040, '2026-11-08 18:00:00')]);

        $merged = ParcelHistory::append($history, self::event(40030, '2026-11-08 12:00:00'));

        self::assertSame([10070, 40030, 40040], array_column($merged, 'code'));
    }

    public function testCorruptJsonIsEmpty(): void
    {
        self::assertSame([], ParcelHistory::decode('{not json'));
        self::assertSame([], ParcelHistory::decode('{"a":1}'));
        self::assertSame([], ParcelHistory::decode(null));
        self::assertSame([], ParcelHistory::decode('[{"code":"x"},5,{"code":606,"raw":"606","name":"a","at":"вчора"}]'));
    }

    public function testEmptyHistoryEncodesToNull(): void
    {
        self::assertNull(ParcelHistory::encode([]));
    }
}
