<?php

namespace Modules\Sviat\RozetkaDelivery\Quote;

use Okay\Modules\Sviat\RozetkaDelivery\Quote\SendingDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SendingDateTest extends TestCase
{
    public static function moments(): iterable
    {
        yield 'before cutoff — today' => ['2026-11-17 15:59', '16:00', '2026-11-17'];
        yield 'at cutoff — tomorrow' => ['2026-11-17 16:00', '16:00', '2026-11-18'];
        yield 'year end' => ['2026-12-31 18:00', '16:00', '2027-01-01'];
    }

    #[DataProvider('moments')]
    public function testAfterCutoffIsTomorrow(string $now, string $cutoff, string $expected): void
    {
        $at = new \DateTimeImmutable($now, new \DateTimeZone('Europe/Kyiv'));

        self::assertSame($expected, SendingDate::for($at->getTimestamp(), $cutoff));
    }

    public function testDstBoundary(): void
    {
        // 29.03.2026 о 03:00 Київ переходить на літній час: 13:30 UTC — це вже 16:30 за Києвом.
        $summer = new \DateTimeImmutable('2026-03-29 13:30', new \DateTimeZone('UTC'));
        self::assertSame('2026-03-30', SendingDate::for($summer->getTimestamp(), '16:00'));
        // 25.10.2026 о 04:00 Київ повертається на зимовий: 13:30 UTC — це 15:30 за Києвом.
        $winter = new \DateTimeImmutable('2026-10-25 13:30', new \DateTimeZone('UTC'));
        self::assertSame('2026-10-25', SendingDate::for($winter->getTimestamp(), '16:00'));
        // Опівночі за Києвом, але ще вчора за UTC.
        $midnight = new \DateTimeImmutable('2026-07-01 21:30', new \DateTimeZone('UTC'));
        self::assertSame('2026-07-02', SendingDate::for($midnight->getTimestamp(), '16:00'));
    }

    public function testSendTimeIsTheCutoff(): void
    {
        $at = new \DateTimeImmutable('2026-11-17 10:00', new \DateTimeZone('Europe/Kyiv'));

        self::assertSame('2026-11-17 16:00:00', SendingDate::sendAt(SendingDate::for($at->getTimestamp(), '16:00'), '16:00'));
    }
}
