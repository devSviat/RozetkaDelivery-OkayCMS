<?php

namespace Modules\Sviat\RozetkaDelivery\Dictionaries\Rows;

use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointKind;
use PHPUnit\Framework\TestCase;

class PointKindTest extends TestCase
{
    public function testKnownTypes(): void
    {
        self::assertSame(PointKind::Pickup, PointKind::fromTypeId(2));
        self::assertSame(PointKind::Store, PointKind::fromTypeId(7));
        self::assertSame(PointKind::Postomat, PointKind::fromTypeId(10));
        self::assertSame(PointKind::Partner, PointKind::fromTypeId(14));
    }

    public function testUnknownIsOther(): void
    {
        self::assertSame(PointKind::Other, PointKind::fromTypeId(0));
        self::assertSame(PointKind::Other, PointKind::fromTypeId(99));
    }
}
