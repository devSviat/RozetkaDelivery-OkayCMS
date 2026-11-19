<?php

namespace Modules\Sviat\RozetkaDelivery\Dictionaries\Rows;

require_once __DIR__ . '/../../Support/DepartmentFixtures.php';

use Modules\Sviat\RozetkaDelivery\Support\DepartmentFixtures;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointKind;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointRow;
use PHPUnit\Framework\TestCase;

class PointRowTest extends TestCase
{
    public function testPrepayOnlyByTypeOrName(): void
    {
        self::assertTrue(PointRow::prepayOnly(14, 'Партнер, вул. Вигадана, 1'));
        self::assertTrue(PointRow::prepayOnly(2, 'Точка (тільки передплата)'));
        self::assertTrue(PointRow::prepayOnly(2, 'Точка (ТІЛЬКИ ПЕРЕДОПЛАТА)'));
        self::assertFalse(PointRow::prepayOnly(2, '№10036, м. Тестове, вул. Вигадана, 5'));
        self::assertTrue(PointRow::fromApi(DepartmentFixtures::point(1), 3)?->prepayOnly);
        self::assertFalse(PointRow::fromApi(DepartmentFixtures::point(0), 3)?->prepayOnly);
    }

    public function testMismatchFlagged(): void
    {
        self::assertFalse(PointRow::fromApi(DepartmentFixtures::point(1), 3)?->prepayMismatch());
        self::assertFalse(PointRow::fromApi(DepartmentFixtures::point(0), 3)?->prepayMismatch());
        self::assertTrue(PointRow::fromApi(DepartmentFixtures::point(1, ['name' => 'Партнер без позначки']), 3)?->prepayMismatch());
        self::assertTrue(PointRow::fromApi(DepartmentFixtures::point(0, ['name' => 'Точка (тільки передоплата)']), 3)?->prepayMismatch());
    }

    public function testNumberFromName(): void
    {
        self::assertSame('10036', PointRow::fromApi(DepartmentFixtures::point(0), 3)?->number);
        self::assertSame('10114', PointRow::fromApi(DepartmentFixtures::point(1), 3)?->number);
        self::assertNull(PointRow::fromApi(DepartmentFixtures::point(2), 3)?->number);
        self::assertNull(PointRow::fromApi(DepartmentFixtures::point(0, ['name' => 'Буд. 12, кв. 345']), 3)?->number);
    }

    public function testUuidLowercased(): void
    {
        $row = PointRow::fromApi(DepartmentFixtures::point(0), 3);

        self::assertSame('0a0b0c0d-1111-4000-8000-000000000001', $row?->id);
        self::assertSame('0a0b0c0d-1111-4000-8000-000000000001', $row->columns()['id']);
        self::assertNull(PointRow::fromApi(DepartmentFixtures::point(0, ['id' => '']), 3));
        self::assertNull(PointRow::fromApi(DepartmentFixtures::point(0, ['location' => null]), 3));
    }

    public function testScheduleKeptAsJson(): void
    {
        $columns = PointRow::fromApi(DepartmentFixtures::point(1), 3)?->columns();

        self::assertSame(['Пн-Пт: 09:00-19:00'], json_decode((string)$columns['schedule'], true));
        self::assertSame([['start' => ['date' => '2026-12-31', 'time' => '09:00:00'], 'end' => ['date' => '2026-12-31', 'time' => '14:00:00']]], json_decode((string)$columns['extra_schedule'], true));
        self::assertNull(PointRow::fromApi(DepartmentFixtures::point(0), 3)?->columns()['extra_schedule']);
    }

    public function testColumns(): void
    {
        $row = PointRow::fromApi(DepartmentFixtures::point(2), 3);
        $columns = $row?->columns();

        self::assertSame(PointKind::Postomat, $row->kind);
        self::assertSame(3, $row->run);
        self::assertSame([
            'id', 'city_id', 'number', 'kind', 'type_id', 'title', 'public_name', 'address', 'lat', 'lng', 'schedule', 'extra_schedule',
            'max_weight', 'max_volume_weight', 'max_length', 'max_cost', 'can_receive', 'can_give_out', 'prepay_only', 'row_hash',
        ], array_keys($columns));
        self::assertSame('просп. Умовний 12', $columns['address']);
        self::assertSame(50.2, $columns['lat']);
        self::assertSame(1, $columns['can_receive']);
        self::assertSame(0, $columns['can_give_out']);
        self::assertSame(30.0, $columns['max_weight']);
        self::assertSame(10, $columns['type_id']);
        self::assertSame($columns['row_hash'], PointRow::fromApi(DepartmentFixtures::point(2), 9)?->columns()['row_hash'], 'run does not change the hash');
    }
}
