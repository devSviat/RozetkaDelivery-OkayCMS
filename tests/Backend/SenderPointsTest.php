<?php

namespace Modules\Sviat\RozetkaDelivery\Backend;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\SenderPoints;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use PHPUnit\Framework\TestCase;

class SenderPointsTest extends TestCase
{
    private function points(mixed $found): SenderPoints
    {
        $points = $this->createStub(PointEntity::class);
        $points->method('cols')->willReturnSelf();
        $points->method('findOne')->willReturn($found);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($points);

        return new SenderPoints($factory);
    }

    /** Сутність з однією колонкою в cols() віддає значення, а не рядок: так відповідає справжня база. */
    public function testReceivingCityFromSingleColumn(): void
    {
        self::assertSame('aaaaaaaa-0000-0000-0000-000000000001', $this->points('aaaaaaaa-0000-0000-0000-000000000001')->receivingCity('dddddddd-0000-0000-0000-000000000001'));
    }

    public function testNotReceivingPointHasNoCity(): void
    {
        self::assertNull($this->points(false)->receivingCity('dddddddd-0000-0000-0000-000000000001'));
        self::assertNull($this->points('')->receivingCity('dddddddd-0000-0000-0000-000000000001'));
        self::assertNull($this->points('x')->receivingCity(''));
    }
}
