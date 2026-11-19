<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Parcels\TrackNumber;
use PHPUnit\Framework\TestCase;

class TrackNumberTest extends TestCase
{
    public function testSpacesRemoved(): void
    {
        self::assertSame('101123456789', TrackNumber::normalize(' 101 123 456 789 '));
        self::assertSame('101123456789', TrackNumber::normalize("101\t123-456-789"));
        self::assertSame('101123456789', TrackNumber::normalize('101123456789'));
    }

    public function testRegistryFormat(): void
    {
        self::assertSame('AAA-123456789', TrackNumber::normalize(' aaa-123 456 789 '));
        self::assertSame('RMP-560339905', TrackNumber::normalize('RMP560339905'));
    }

    public function testGarbageRejected(): void
    {
        foreach (['', '12345', '1011234567890', 'AB-123456789', '<b>101123456789</b>', 'ABCD-123456789'] as $raw) {
            self::assertNull(TrackNumber::normalize($raw), $raw);
        }
    }
}
