<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Parcels\TrackingAnswer;
use PHPUnit\Framework\TestCase;

/** `GET /api/track/status` — схема описує `data` як один об'єкт, хоча запит приймає список номерів. */
class TrackingAnswerTest extends TestCase
{
    public static function item(string $trackId, int|string $last = 40030, string $date = '2027-01-16 09:00:00'): array
    {
        return [
            'track_id' => $trackId,
            'statuses' => [
                ['status' => 10010, 'date' => '2027-01-15 10:00:00', 'status_name' => 'Заплановано'],
                ['status' => $last, 'date' => $date, 'status_name' => 'Відправлення у відділенні'],
            ],
            'last_status' => ['status' => $last, 'date' => $date, 'status_name' => 'Відправлення у відділенні'],
        ];
    }

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
    }

    public function testObjectOrArray(): void
    {
        $one = TrackingAnswer::parse(self::item('101123456789'));
        $many = TrackingAnswer::parse([self::item('101123456789'), self::item('101123456790', 'readyToIssueOctopus')]);

        self::assertCount(1, $one);
        self::assertSame('101123456789', $one[0]['track_id']);
        self::assertSame(40030, $one[0]['last']->code);
        self::assertSame('2027-01-16 07:00:00', $one[0]['last']->at, 'Kyiv time read into the app zone');
        self::assertCount(2, $one[0]['statuses']);
        self::assertSame(['101123456789', '101123456790'], array_column($many, 'track_id'));
        self::assertSame(40040, $many[1]['last']->code, 'mapped by name');
        self::assertSame('readyToIssueOctopus', $many[1]['last']->raw);
    }

    public function testGarbageSkipped(): void
    {
        self::assertSame([], TrackingAnswer::parse(null));
        self::assertSame([], TrackingAnswer::parse('x'));
        self::assertSame([], TrackingAnswer::parse([['track_id' => '../1'], ['statuses' => []]]));
        $noLast = TrackingAnswer::parse([['track_id' => '101123456789', 'statuses' => [['status' => 10010, 'date' => 'вчора']]]]);
        self::assertNull($noLast[0]['last']);
        self::assertSame([], $noLast[0]['statuses']);
    }
}
