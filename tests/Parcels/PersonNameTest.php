<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PersonName;
use PHPUnit\Framework\TestCase;

class PersonNameTest extends TestCase
{
    public function testSplitVariants(): void
    {
        self::assertSame(['last' => 'Тестова', 'first' => 'Олена', 'middle' => ''], PersonName::split('Тестова', 'Олена'));
        self::assertSame(['last' => 'Тестова', 'first' => 'Олена', 'middle' => 'Іванівна'], PersonName::split('Тестова', 'Олена Іванівна'));
        self::assertSame(['last' => 'Тестова', 'first' => 'Олена', 'middle' => ''], PersonName::split('', 'Олена  Тестова'), 'two words: first name, then last name');
        self::assertSame(['last' => 'Тестова', 'first' => 'Олена', 'middle' => 'Іванівна'], PersonName::split(' ', 'Тестова Олена Іванівна'), 'three words: last, first, middle');
    }

    public function testSingleWordNull(): void
    {
        self::assertNull(PersonName::split('', 'Олена'));
        self::assertNull(PersonName::split('', ''));
        self::assertNull(PersonName::split('Тестова', ''));
    }
}
