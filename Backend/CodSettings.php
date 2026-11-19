<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;

/** Блок «Післяплата»: прапорець, формула комісії для кошика й те, хто фіскалізує. */
class CodSettings
{
    private const float FEE_FIXED_MAX = 1000;
    private const float FEE_PERCENT_MAX = 100;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function read(): CodConfig
    {
        return CodConfig::fromSettings($this->settings->get(...));
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string> поле → мовний ключ помилки; якщо вони є, нічого не зберігається
     */
    public function save(array $post): array
    {
        $text = static fn(string $field): string => is_string($post[$field] ?? null) ? str_replace(',', '.', trim($post[$field])) : '';
        $errors = [];
        $fixed = self::number($text('cod_fee_fixed'));
        if ($fixed === null || $fixed > self::FEE_FIXED_MAX) {
            $errors['cod_fee_fixed'] = 'sviat__rozetka__cod_err_fee';
        }
        $percent = self::number($text('cod_fee_percent'));
        if ($percent === null || $percent > self::FEE_PERCENT_MAX) {
            $errors['cod_fee_percent'] = 'sviat__rozetka__cod_err_fee';
        }
        $fiscal = $text('cod_fiscal');
        if (!in_array($fiscal, CodConfig::FISCAL_CHOICES, true)) {
            $errors['cod_fiscal'] = 'sviat__rozetka__cod_err_fiscal';
        }
        if ($errors !== []) {
            return $errors;
        }
        $this->settings->set(CodConfig::ENABLED, empty($post['cod_enabled']) ? 0 : 1);
        $this->settings->set(CodConfig::FEE_FIXED, $fixed);
        $this->settings->set(CodConfig::FEE_PERCENT, $percent);
        $this->settings->set(CodConfig::FISCAL, $fiscal);

        return [];
    }

    private static function number(string $raw): ?float
    {
        return preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $raw) === 1 ? (float)$raw : null;
    }
}
