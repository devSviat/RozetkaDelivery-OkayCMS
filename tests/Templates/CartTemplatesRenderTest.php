<?php

namespace Modules\Sviat\RozetkaDelivery\Templates;

require_once __DIR__ . '/../Support/TemplateSmarty.php';

use Modules\Sviat\RozetkaDelivery\Support\TemplateSmarty;
use Okay\Modules\Sviat\RozetkaDelivery\Cart\ThemeClasses;
use PHPUnit\Framework\TestCase;

/** Шаблони вітрини й листів, відрендерені справжнім Smarty: форма надсилає поля, які читає валідатор. */
class CartTemplatesRenderTest extends TestCase
{
    private const string CITY = 'cccccccc-0000-4000-8000-000000000001';

    private static function root(): string
    {
        return dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery';
    }

    private static function render(string $template, array $vars, bool $backend = false): string
    {
        $dir = self::root() . ($backend ? '/Backend/design/html' : '/design/html');
        $smarty = TemplateSmarty::create([$dir], sys_get_temp_dir() . '/okaycms-rozetka-tpl-' . getmypid());
        $lang = [];
        include self::root() . ($backend ? '/Backend/lang/ua.php' : '/design/lang/ua.php');
        $smarty->assign($backend ? 'btr' : 'lang', (object)$lang);
        foreach ($vars as $name => $value) {
            $smarty->assign($name, $value);
        }

        return $smarty->fetch($template);
    }

    public function testCartFormCarriesIdsAndCodData(): void
    {
        $html = self::render('cart_delivery.tpl', [
            'delivery' => (object)['id' => 6, 'module_id' => 81],
            'rozetka_module_id' => 81,
            'rozetka_unavailable' => false,
            'rozetka_theme' => ThemeClasses::for('okay_shop'),
            'rozetka_popular' => [],
            'rozetka_texts_json' => '{"cart_busy":"\"<b>\""}',
            'rozetka_urls_json' => '{"date":"https://shop.test/ajax/rozetka/date"}',
            'rozetka_cod' => [6 => ['payments' => '1,3', 'fee' => '30.00 грн']],
            'rozetka_prefill' => [6 => [
                'city' => ['id' => self::CITY, 'name' => 'Тестове <script>', 'type' => 'м.', 'district' => '', 'region' => 'Вигадана'],
                'point' => null,
                'city_json' => '{"id":"' . self::CITY . '","name":"Тестове <script>"}',
                'point_json' => '',
            ]],
        ]);

        self::assertMatchesRegularExpression('/name="rozetka_city_id\[6\]" value="' . self::CITY . '"/', $html);
        self::assertMatchesRegularExpression('/name="rozetka_point_id\[6\]" value=""/', $html);
        self::assertStringContainsString('data-cod-payments="1,3"', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testCodFeeLineOnlyForMarkedPayment(): void
    {
        self::assertSame('<span class="rz-cod-line" data-rz-cod-payment="4" hidden></span>', self::render('cart_cod_fee.tpl', ['payment_method' => (object)['id' => 4, 'sviat__rozetka__cod' => 1]]));
        self::assertSame('', self::render('cart_cod_fee.tpl', ['payment_method' => (object)['id' => 4, 'sviat__rozetka__cod' => 0]]), 'the theme shows the description block for any output');
    }

    public function testOrderLinesWithCodFee(): void
    {
        $delivery = ['city' => 'м. Тестове', 'point' => '№10001 <b>', 'estimatedDate' => '2026-11-21', 'receiverPays' => true, 'cod' => true, 'codFee' => 30.0];

        $page = self::render('order_delivery.tpl', ['rozetka_order_delivery' => $delivery, 'rozetka_order_family' => 'okay']);
        self::assertStringContainsString('так, комісія ≈ 30.00 грн, сплачується під час отримання', $page);
        self::assertStringContainsString('№10001 &lt;b&gt;', $page);
        self::assertStringContainsString('21.11.2026', $page);

        $mail = self::render('email_delivery.tpl', ['rozetka_order_delivery' => ['codFee' => null] + $delivery]);
        self::assertStringContainsString('одержувач', $mail);

        $_SESSION = ['id' => 'tok'];
        $admin = self::render('order_delivery.tpl', [
            'rozetka_order_delivery' => $delivery,
            'rozetka_order_point' => ['orderId' => 9, 'needsChoice' => false, 'locked' => false, 'cityId' => self::CITY, 'cityName' => 'м. Тестове', 'pointId' => null, 'currency' => 'грн', 'textsJson' => '{}'],
        ], backend: true);
        unset($_SESSION);
        self::assertStringContainsString('комісія ≈ 30.00 грн', $admin);
        self::assertStringContainsString('controller=Sviat.RozetkaDelivery.OrderPointAdmin', $admin);
    }
}
