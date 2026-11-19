<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Parcels\RozetkaPhone;
use PHPUnit\Framework\TestCase;

class RozetkaPhoneTest extends TestCase
{
    public function testFormsAreNormalized(): void
    {
        foreach (['+380501112233', '380501112233', '0501112233', '+38 (050) 111-22-33', ' 050 111 22 33 '] as $raw) {
            self::assertSame('380501112233', RozetkaPhone::normalize($raw), $raw);
        }
    }

    public function testLandlineAndGarbageRejected(): void
    {
        foreach (['0442223344', '+380441112233', '050111223', 'abc0501112233', '', '+3805011122334'] as $raw) {
            self::assertNull(RozetkaPhone::normalize($raw), $raw);
        }
    }
}
