<?php

namespace Modules\Sviat\RozetkaDelivery\Dictionaries\Rows;

require_once __DIR__ . '/../../Support/DepartmentFixtures.php';

use Modules\Sviat\RozetkaDelivery\Support\DepartmentFixtures;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\CityRow;
use PHPUnit\Framework\TestCase;

class CityRowTest extends TestCase
{
    public function testTypeSplit(): void
    {
        $city = CityRow::fromPoint(DepartmentFixtures::point(0)['location']);

        self::assertSame('м.', $city?->type);
        self::assertSame('Тестове', $city->name);
        self::assertSame('с.', CityRow::fromPoint(DepartmentFixtures::point(1)['location'])?->type);
        $bare = CityRow::fromPoint(['city_id' => 'C1', 'city_name' => 'Боярка']);
        self::assertNull($bare?->type);
        self::assertSame('Боярка', $bare->name);
        self::assertSame('c1', $bare->id);
        self::assertSame('смт', CityRow::fromPoint(['city_id' => 'c2', 'city_name' => 'смт Умовне'])?->type);
        self::assertNull(CityRow::fromPoint(['city_name' => 'м. Без id']));
    }

    public function testColumns(): void
    {
        $columns = CityRow::fromPoint(DepartmentFixtures::point(0)['location'])?->columns();

        self::assertSame(['id', 'region_id', 'district_id', 'type', 'name_ua', 'district_ua', 'region_ua', 'search_text', 'row_hash'], array_keys($columns));
        self::assertSame('тестове', $columns['search_text']);
        self::assertSame('Умовна', $columns['region_ua']);
        self::assertSame('Умовний', $columns['district_ua']);
    }
}
