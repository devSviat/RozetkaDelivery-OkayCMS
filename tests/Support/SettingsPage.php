<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

use Okay\Core\BackendTranslations;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;

/** Сторінка налаштувань, відрендерена справжнім Smarty з тими змінними, які призначає контролер. */
final class SettingsPage
{
    /** @param array<string, mixed> $vars перекривають типові */
    public static function render(array $vars = [], string $language = 'ua', string $template = 'settings.tpl'): string
    {
        $root = dirname(__DIR__, 5);
        require_once __DIR__ . '/TemplateSmarty.php';
        $smarty = TemplateSmarty::create(
            [$root . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/design/html', $root . '/backend/design/html'],
            sys_get_temp_dir() . '/okaycms-rozetka-admin-tpl-' . getmypid(),
        );
        $btr = new \ReflectionClass(BackendTranslations::class)->newInstanceWithoutConstructor();
        foreach (self::lang($language) as $key => $text) {
            $btr->addTranslation($key, $text);
        }
        $smarty->assign('btr', $btr);
        $_SESSION['id'] ??= 'test-session';
        $_SERVER['REQUEST_URI'] ??= '/backend/index.php';
        foreach ($vars + self::defaults() as $name => $value) {
            $smarty->assign($name, $value);
        }

        return $smarty->fetch($template);
    }

    /** @return array<string, string> */
    public static function lang(string $language): array
    {
        $lang = [];
        include dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/lang/' . $language . '.php';

        return $lang;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'rozetka_access' => [
                'saved' => false,
                'token_tail' => null,
                'token_id' => null,
                'last_check' => null,
                'partner' => null,
                'warnings' => [],
                'flags' => [],
            ],
            'rozetka_issue' => ['phone' => ''],
            'rozetka_sender' => [
                'values' => ['last_name' => '', 'first_name' => '', 'middle_name' => '', 'phone' => '', 'point_id' => '', 'city_id' => ''],
                'city' => null,
                'points' => [],
            ],
            'rozetka_shipping' => [
                'values' => [
                    'weight' => '1', 'length' => '30', 'width' => '20', 'height' => '15', 'receiver_pay' => true, 'declare_total' => true,
                    'declared_value' => '', 'description' => 'Замовлення №{order}', 'cutoff' => '16:00', 'cart_price' => '49', 'receiver_price_note' => true,
                ],
                'cutoff_choices' => ['15:30', '16:00', '16:30'],
            ],
            'rozetka_cod' => new CodConfig(),
            'rozetka_dict' => [
                'state' => 'none', 'updated_at' => null, 'attempted_at' => null,
                'counts' => ['cities' => 0, 'statuses' => 0, 'pickup' => 0, 'store' => 0, 'postomat' => 0, 'partner' => 0, 'other' => 0],
                'queued' => false, 'scheduler_idle' => false, 'can_run' => true, 'error' => null, 'reason' => null, 'received' => null, 'total' => null,
                'statuses_error' => null, 'unknown_types' => [], 'prepay_mismatch' => ['count' => 0, 'names' => []], 'limit_units_suspect' => 0,
            ],
            'rozetka_groups' => ['groups' => [], 'kinds' => []],
            'rozetka_popular' => [],
            'rozetka_delivery_warnings' => [],
            'rozetka_codpay' => ['enabled' => false, 'on' => false, 'manual' => true],
        ];
    }
}
