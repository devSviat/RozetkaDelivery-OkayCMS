<?php

namespace Modules\Sviat\RozetkaDelivery\Templates;

use PHPUnit\Framework\TestCase;

/** Назви, адреси й тексти Rozetka потрапляють на сторінку лише через textContent/createElement. */
class CartScriptTest extends TestCase
{
    private const string ROOT = '/Okay/Modules/Sviat/RozetkaDelivery';

    private static function script(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 5) . self::ROOT . '/design/js/cart.js');
    }

    public function testNoInnerHtml(): void
    {
        $root = dirname(__DIR__, 5) . self::ROOT;
        $files = [...(glob($root . '/design/js/*.js') ?: []), ...(glob($root . '/Backend/design/js/*.js') ?: [])];
        self::assertContains($root . '/design/js/cart.js', $files);
        self::assertContains($root . '/Backend/design/js/order_point.js', $files);

        $offenders = [];
        foreach ($files as $file) {
            if (preg_match('/innerHTML|outerHTML|insertAdjacentHTML|\.html\(|document\.write/', (string)file_get_contents($file), $m) === 1) {
                $offenders[] = basename($file) . ': ' . $m[0];
            }
        }

        self::assertSame([], $offenders);
    }

    /** Точка «лише передоплата» блокує оплати-післяплати, які шаблон позначив data-rz-cod-payment. */
    public function testCodPaymentsDisabledForPrepayOnly(): void
    {
        $script = self::script();
        $sync = substr($script, (int)strpos($script, 'Block.prototype.syncPayments'), 3000);

        self::assertStringContainsString('prepay_only', $sync);
        self::assertStringContainsString('input.disabled = true', $sync);
        self::assertStringContainsString('data-rz-cod-payment', $sync);
        self::assertStringContainsString("on('change', 'input[name=\"payment_method_id\"]'", $script, 'a payment change re-checks the chosen point');
        self::assertStringContainsString('pointRefusesCod', $script);
        self::assertMatchesRegularExpression('/data-rz-cod-payment="\{\$payment_method->id\|escape\}"/', (string)file_get_contents(dirname(__DIR__, 5) . self::ROOT . '/design/html/cart_cod_fee.tpl'));
    }

    /** Точки сіріють за проблемами для поточної оплати: сервер дає обидва набори. */
    public function testPointProblemsFollowPayment(): void
    {
        self::assertMatchesRegularExpression('/codChosen\(\)\s*\?\s*point\.cod_problems\s*:\s*point\.problems/', self::script());
    }
}
