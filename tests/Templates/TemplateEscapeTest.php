<?php

namespace Modules\Sviat\RozetkaDelivery\Templates;

use Okay\Modules\Sviat\RozetkaDelivery\Init\Init;
use PHPUnit\Framework\TestCase;

/**
 * Шаблони адмінки й теми не мають autoescape: кожне виведене значення потребує |escape. Без нього дозволено
 * присвоєння, capture (його частини екрановано там, де їх захоплено) і |convert (число, відформатоване ядром).
 */
class TemplateEscapeTest extends TestCase
{
    public function testEveryPrintedValueIsEscaped(): void
    {
        $root = dirname((string)new \ReflectionClass(Init::class)->getFileName(), 2);
        $files = [...(glob($root . '/Backend/design/html/*.tpl') ?: []), ...(glob($root . '/design/html/*.tpl') ?: [])];
        // Кошик, сторінка замовлення й листи друкують назви точок із довідника: перевірка мусить їх бачити.
        foreach (['/design/html/cart_delivery.tpl', '/design/html/order_delivery.tpl', '/design/html/email_delivery.tpl', '/Backend/design/html/order_delivery.tpl'] as $template) {
            self::assertContains($root . $template, $files);
        }

        self::assertSame([], self::raw($files));
    }

    public function testDetectorSeesRawOutput(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($file, "<b>{\$point.title}</b>{\$x|escape}{\$y = 1}{\$n|convert}\n");
        try {
            self::assertSame([basename($file) . ':1 {$point.title}'], array_map(static fn(string $r): string => substr($r, strpos($r, '/') + 1), self::raw([$file])));
        } finally {
            unlink($file);
        }
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private static function raw(array $files): array
    {
        $raw = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $n => $line) {
                preg_match_all('/\{\$[^}]*\}/', $line, $m);
                foreach ($m[0] as $tag) {
                    if (str_contains($tag, '|escape') || preg_match('/^\{\$[\w.>-]+\s*=/', $tag) === 1 || str_starts_with($tag, '{$smarty.capture.') || str_contains($tag, '|convert')) {
                        continue;
                    }
                    $raw[] = basename(dirname($file, 2)) . '/' . basename($file) . ':' . ($n + 1) . ' ' . $tag;
                }
            }
        }

        return $raw;
    }
}
