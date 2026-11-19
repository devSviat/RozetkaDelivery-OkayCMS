<?php

namespace Modules\Sviat\RozetkaDelivery\Templates;

use PHPUnit\Framework\TestCase;

/** Адмінка стоку OkayCMS 4.5.3 не має дизайн-токенів форку `--ok-*`: без запасного значення властивість зникає. */
class DesignTokenFallbackTest extends TestCase
{
    public function testEveryForkTokenHasAFallback(): void
    {
        $root = dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $bare = [];
        $seen = 0;
        foreach ($files as $file) {
            if (!in_array($file->getExtension(), ['css', 'tpl', 'js'], true)) {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            $seen += preg_match_all('/var\(\s*--ok-/', $source);
            preg_match_all('/var\(\s*--ok-[a-z0-9-]+\s*\)/', $source, $found);
            foreach ($found[0] as $use) {
                $bare[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $use;
            }
        }

        self::assertGreaterThan(0, $seen, 'the module uses fork tokens, so the check has something to look at');
        self::assertSame([], $bare);
    }
}
