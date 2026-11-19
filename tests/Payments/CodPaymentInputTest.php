<?php

namespace Modules\Sviat\RozetkaDelivery\Payments;

use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPaymentInput;
use PHPUnit\Framework\TestCase;

class CodPaymentInputTest extends TestCase
{
    public function testFlagOffLeavesColumn(): void
    {
        $post = [CodPaymentInput::MARKER => '1', CodPaymentInput::SWITCH => '1'];

        self::assertNull(CodPaymentInput::flag($post, 'null', false));
        self::assertNull(CodPaymentInput::flag([CodPaymentInput::MARKER => '1'], 'null', false));
    }

    public function testFlagOnSavesSwitch(): void
    {
        self::assertSame(1, CodPaymentInput::flag([CodPaymentInput::MARKER => '1', CodPaymentInput::SWITCH => '1'], 'null', true));
        self::assertSame(0, CodPaymentInput::flag([CodPaymentInput::MARKER => '1'], null, true));
        self::assertSame(0, CodPaymentInput::flag([CodPaymentInput::MARKER => '1', CodPaymentInput::SWITCH => '1'], 'Sviat/Monobank', true), 'online payment is never COD');
        self::assertNull(CodPaymentInput::flag([CodPaymentInput::SWITCH => '1'], 'null', true), 'no marker: the POST came from elsewhere');
    }
}
