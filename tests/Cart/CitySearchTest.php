<?php

namespace Modules\Sviat\RozetkaDelivery\Cart;

use Okay\Modules\Sviat\RozetkaDelivery\Cart\CitySearch;
use PHPUnit\Framework\TestCase;

class CitySearchTest extends TestCase
{
    private const string A = 'bbbbbbbb-0000-4000-8000-000000000011';
    private const string B = 'bbbbbbbb-0000-4000-8000-000000000012';

    private static function row(string $id, string $name, ?string $district, ?string $region): object
    {
        return (object)['id' => $id, 'name_ua' => $name, 'type' => 'с.', 'district_ua' => $district, 'region_ua' => $region];
    }

    public function testNamesakesDisambiguated(): void
    {
        $found = CitySearch::present([
            self::row(self::A, 'Вигадане', 'Перший', 'Тестова'),
            self::row(self::B, 'Вигадане', 'Другий', 'Тестова'),
        ]);

        self::assertSame([
            ['id' => self::A, 'name' => 'Вигадане', 'type' => 'с.', 'district' => 'Перший', 'region' => 'Тестова'],
            ['id' => self::B, 'name' => 'Вигадане', 'type' => 'с.', 'district' => 'Другий', 'region' => 'Тестова'],
        ], $found, 'two rows, told apart by district; the choice is by UUID');
        self::assertSame('', CitySearch::present([self::row(self::A, 'Вигадане', null, null)])[0]['district']);
    }

    public function testQueryIsNormalisedLikeSearchText(): void
    {
        self::assertSame(['вигадане'], CitySearch::words('  ВИГАДАНЕ '));
        self::assertSame(['мар\'їне'], CitySearch::words('Мар’їне'));
        self::assertSame([], CitySearch::words('місто'), 'a bare type matches everything');
        self::assertSame(['100\%'], CitySearch::words('100%'), 'LIKE wildcards are escaped');
    }
}
