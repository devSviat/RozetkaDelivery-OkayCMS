<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Payments;

/**
 * Налаштування післяплати ФК «ЕВО». Типово вимкнена: хто платить комісію й хто фіскалізує, ще не відомо.
 * Комісія — лише для показу в кошику; фактичну повертає створення ЕН.
 */
final readonly class CodConfig
{
    public const string ENABLED = 'sviat__rozetka__cod_enabled';
    public const string FEE_FIXED = 'sviat__rozetka__cod_fee_fixed';
    public const string FEE_PERCENT = 'sviat__rozetka__cod_fee_percent';
    public const string FISCAL = 'sviat__rozetka__cod_fiscal';

    /** Чек видає магазин (Checkbox). */
    public const string FISCAL_SHOP = 'shop';
    /** Чек видає ЕВО чи Rozetka. */
    public const string FISCAL_PROVIDER = 'provider';
    /** Не визначено: автоматичного чека немає, щоб не видати другий. */
    public const string FISCAL_UNKNOWN = 'unknown';
    public const array FISCAL_CHOICES = [self::FISCAL_SHOP, self::FISCAL_PROVIDER, self::FISCAL_UNKNOWN];

    public const float DEFAULT_FEE_FIXED = 15.0;
    public const float DEFAULT_FEE_PERCENT = 1.5;

    public function __construct(
        public bool $enabled = false,
        public float $feeFixed = self::DEFAULT_FEE_FIXED,
        public float $feePercent = self::DEFAULT_FEE_PERCENT,
        public string $fiscal = self::FISCAL_UNKNOWN,
    ) {
    }

    /** @param callable(string): mixed $get Settings::get */
    public static function fromSettings(callable $get): self
    {
        $text = static fn(string $key): string => is_scalar($value = $get($key)) ? trim((string)$value) : '';
        $number = static fn(string $key, float $default): float => is_numeric($text($key)) && (float)$text($key) >= 0 ? (float)$text($key) : $default;
        $fiscal = $text(self::FISCAL);

        return new self(
            $text(self::ENABLED) === '1',
            $number(self::FEE_FIXED, self::DEFAULT_FEE_FIXED),
            $number(self::FEE_PERCENT, self::DEFAULT_FEE_PERCENT),
            in_array($fiscal, self::FISCAL_CHOICES, true) ? $fiscal : self::FISCAL_UNKNOWN,
        );
    }

    /** Оцінка комісії за суму до сплати, з точністю до копійки. */
    public function estimateFee(float $due): float
    {
        return round($this->feeFixed + $this->feePercent / 100 * $due, 2);
    }
}
