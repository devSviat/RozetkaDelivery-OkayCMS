<?php

namespace Modules\Sviat\RozetkaDelivery\Dictionaries\Rows;

use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointLimits;
use PHPUnit\Framework\TestCase;

class PointLimitsTest extends TestCase
{
    public function testNullMeansNoLimit(): void
    {
        foreach ([null, [], ['weight' => null, 'volumeWeight' => 0, 'length' => '', 'cost' => 'x']] as $limitations) {
            $limits = PointLimits::fromApi($limitations);
            self::assertNull($limits->maxWeight);
            self::assertNull($limits->maxVolumeWeight);
            self::assertNull($limits->maxLength);
            self::assertNull($limits->maxCost);
            self::assertFalse($limits->suspectUnits);
            self::assertSame(['max_weight' => 15.0, 'max_volume_weight' => 30.0, 'max_length' => 120.0, 'max_cost' => 30000.0], $limits->effective());
        }
    }

    public function testGramsSuspected(): void
    {
        $limits = PointLimits::fromApi(['weight' => 15000, 'volumeWeight' => 30]);

        self::assertSame(15.0, $limits->maxWeight);
        self::assertSame(30.0, $limits->maxVolumeWeight);
        self::assertTrue($limits->suspectUnits);
        self::assertFalse(PointLimits::fromApi(['weight' => 150, 'volumeWeight' => 40])->suspectUnits);
    }

    public function testEffectiveTakesMinimum(): void
    {
        self::assertSame(30000.0, PointLimits::fromApi(['cost' => 60000])->effective()['max_cost']);
        self::assertSame(20000.0, PointLimits::fromApi(['cost' => 20000])->effective()['max_cost']);
        self::assertSame(10.0, PointLimits::fromApi(['weight' => 10])->effective()['max_weight']);
        self::assertSame(15.0, PointLimits::fromApi(['weight' => 150])->effective()['max_weight']);
        self::assertSame(30.0, PointLimits::fromApi(['volumeWeight' => 9000])->effective()['max_volume_weight']);
        self::assertSame(80.0, PointLimits::fromApi(['length' => 80])->effective()['max_length']);
    }
}
