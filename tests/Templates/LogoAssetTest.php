<?php

namespace Modules\Sviat\RozetkaDelivery\Templates;

use PHPUnit\Framework\TestCase;

/** SVG у модулі віддається браузеру як є: лише фігури, без скриптів, обробників подій і посилань назовні. */
class LogoAssetTest extends TestCase
{
    public function testLogoIsPlainShapes(): void
    {
        $file = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/design/images/rozetka.svg';
        $svg = (string)file_get_contents($file);
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($svg, LIBXML_NONET));

        $tags = [];
        foreach ($doc->getElementsByTagName('*') as $node) {
            $tags[$node->localName] = true;
            foreach ($node->attributes as $attribute) {
                self::assertDoesNotMatchRegularExpression('/^on|href/i', $attribute->name);
                self::assertDoesNotMatchRegularExpression('/url\(|javascript:/i', $attribute->value);
            }
        }
        self::assertSame(['svg', 'path'], array_keys($tags));
        self::assertStringNotContainsString('xlink', $svg);
    }
}
