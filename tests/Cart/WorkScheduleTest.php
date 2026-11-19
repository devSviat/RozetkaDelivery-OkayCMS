<?php

namespace Modules\Sviat\RozetkaDelivery\Cart;

use Okay\Modules\Sviat\RozetkaDelivery\Cart\WorkSchedule;
use PHPUnit\Framework\TestCase;

class WorkScheduleTest extends TestCase
{
    public function testParsesLinesAndHolidays(): void
    {
        $lines = ['Пн: 08:30-18:00', 'Вт: 08:30-18:00', 'Ср: 08:30-18:00', 'Чт: 08:30-18:00', 'Пт: 08:30-18:00', 'Сб: 09:00-15:00', 'Нд: вихідний'];
        $extra = [
            ['start' => ['date' => '2026-12-31', 'time' => '09:00:00'], 'end' => ['date' => '2026-12-31', 'time' => '14:00:00']],
            ['start' => ['date' => '2026-11-01', 'time' => '09:00:00'], 'end' => ['date' => '2026-11-01', 'time' => '14:00:00']],
            ['start' => ['date' => '2027-01-01'], 'end' => ['date' => '2027-01-01']],
            'not a day',
        ];

        self::assertSame([
            'week' => [
                ['from' => 0, 'to' => 4, 'hours' => '08:30–18:00', 'break' => null],
                ['from' => 5, 'to' => 5, 'hours' => '09:00–15:00', 'break' => null],
                ['from' => 6, 'to' => 6, 'hours' => null, 'break' => null],
            ],
            'special' => [
                ['date' => '2026-12-31', 'hours' => '09:00–14:00'],
                ['date' => '2027-01-01', 'hours' => null],
            ],
        ], WorkSchedule::parse($lines, $extra, '2026-11-18'), 'past special days are dropped; a day without hours is closed');
    }

    public function testRoundTheClockAndUnreadable(): void
    {
        $allDay = array_map(static fn(string $d): string => "{$d}: 00:00-23:59", ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд']);

        self::assertSame(['week' => [['allDay' => true]], 'special' => []], WorkSchedule::parse($allDay, null, '2026-11-18'));
        self::assertSame(['week' => null, 'special' => []], WorkSchedule::parse(['щось незрозуміле', 42], null, '2026-11-18'));
    }

    public function testBreakIsSecondInterval(): void
    {
        $week = WorkSchedule::parse(['Пн: 09:00-18:00, 13:00-14:00'], null, '2026-11-18')['week'];

        self::assertSame(['from' => 0, 'to' => 0, 'hours' => '09:00–18:00', 'break' => '13:00–14:00'], $week[0]);
    }

    public function testFromColumns(): void
    {
        self::assertSame(['week' => null, 'special' => []], WorkSchedule::fromColumns(null, null, '2026-11-18'));
        self::assertSame(
            [['from' => 0, 'to' => 0, 'hours' => '08:00–20:00', 'break' => null], ['from' => 1, 'to' => 6, 'hours' => null, 'break' => null]],
            WorkSchedule::fromColumns('["Пн: 08:00-20:00"]', '[]', '2026-11-18')['week'],
            'Rozetka lists all seven days; a day it leaves out is shown as closed',
        );
    }
}
