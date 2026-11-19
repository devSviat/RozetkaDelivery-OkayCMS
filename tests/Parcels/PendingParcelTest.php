<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PendingParcel;
use PHPUnit\Framework\TestCase;

class PendingParcelTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private static function reserved(int $secondsAgo, string $state = PendingParcel::PENDING): object
    {
        return (object)['state' => $state, 'reserved_at' => date('Y-m-d H:i:s', self::NOW - $secondsAgo)];
    }

    public function testFreshReserveIsRunning(): void
    {
        self::assertTrue(PendingParcel::running(self::reserved(30), self::NOW));
        self::assertFalse(PendingParcel::needsCheck(self::reserved(600), self::NOW));
    }

    public function testStaleReserveNeedsCheck(): void
    {
        self::assertTrue(PendingParcel::needsCheck(self::reserved(601), self::NOW));
        self::assertFalse(PendingParcel::running(self::reserved(601), self::NOW));
        self::assertTrue(PendingParcel::needsCheck((object)['state' => PendingParcel::PENDING, 'reserved_at' => null, 'created_at' => null], self::NOW), 'unknown time is not free');
    }

    public function testUncertainAndCreated(): void
    {
        self::assertTrue(PendingParcel::needsCheck(self::reserved(5, PendingParcel::UNCERTAIN), self::NOW));
        self::assertFalse(PendingParcel::is((object)['state' => PendingParcel::CREATED]));
        self::assertFalse(PendingParcel::needsCheck(null, self::NOW));
    }
}
