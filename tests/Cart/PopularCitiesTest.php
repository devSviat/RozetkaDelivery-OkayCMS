<?php

namespace Modules\Sviat\RozetkaDelivery\Cart;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\RozetkaDelivery\Support\InMemoryState;
use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\PopularCities;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use PHPUnit\Framework\TestCase;

class PopularCitiesTest extends TestCase
{
    /** Сутність з однією колонкою в cols() віддає значення, а не рядки: так відповідає справжня база. */
    public function testSaveKeepsOnlyExistingCitiesInManagerOrder(): void
    {
        $cities = $this->createStub(CityEntity::class);
        $cities->method('cols')->willReturnSelf();
        $cities->method('noLimit')->willReturnSelf();
        $cities->method('find')->willReturn(['bbbbbbbb-0000-0000-0000-000000000002', 'aaaaaaaa-0000-0000-0000-000000000001']);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($cities);
        $state = new InMemoryState();

        new PopularCities($state, $factory)->save(['AAAAAAAA-0000-0000-0000-000000000001', 'cccccccc-0000-0000-0000-000000000003', 'bbbbbbbb-0000-0000-0000-000000000002']);

        self::assertSame(['aaaaaaaa-0000-0000-0000-000000000001', 'bbbbbbbb-0000-0000-0000-000000000002'], PopularCities::stored($state));
    }
}
