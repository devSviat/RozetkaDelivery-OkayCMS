<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointLimits;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;

/** Блок «Параметри ЕН і ціна в кошику»: з цих значень заповнюється форма ЕН і рахується доставка в кошику. */
final class ShippingSettings
{
    public const int DESCRIPTION_MAX = 250;
    public const float PRICE_MAX = 99999.99;

    private const array SIDES = [
        'box_length' => ShippingConfig::BOX_LENGTH,
        'box_width' => ShippingConfig::BOX_WIDTH,
        'box_height' => ShippingConfig::BOX_HEIGHT,
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function read(): ShippingConfig
    {
        return ShippingConfig::fromSettings($this->settings->get(...));
    }

    /** @return array<string, string|bool> значення полів форми зі збережених налаштувань */
    public static function form(ShippingConfig $config): array
    {
        return [
            'weight' => (string)$config->defaultWeightKg,
            'length' => (string)$config->lengthCm,
            'width' => (string)$config->widthCm,
            'height' => (string)$config->heightCm,
            'receiver_pay' => $config->receiverPay,
            'declare_total' => $config->declareTotal,
            'declared_value' => $config->declaredValue > 0 ? (string)$config->declaredValue : '',
            'description' => $config->description,
            'cutoff' => $config->cutoff,
            'cart_price' => (string)$config->cartPrice,
            'receiver_price_note' => $config->receiverPriceNote,
        ];
    }

    /**
     * Надіслане як є: форму з помилками показуємо з тим, що менеджер ввів.
     *
     * @param array<string, mixed> $post
     * @return array<string, string|bool>
     */
    public static function formFromPost(array $post): array
    {
        $text = static fn(string $field): string => is_string($post[$field] ?? null) ? $post[$field] : '';

        return [
            'weight' => $text('default_weight'),
            'length' => $text('box_length'),
            'width' => $text('box_width'),
            'height' => $text('box_height'),
            'receiver_pay' => !empty($post['receiver_pay']),
            'declare_total' => !empty($post['declare_total']),
            'declared_value' => $text('declared_value'),
            'description' => $text('description'),
            'cutoff' => $text('cutoff'),
            'cart_price' => $text('cart_price'),
            'receiver_price_note' => !empty($post['receiver_price_note']),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string> поле форми → мовний ключ помилки; якщо вони є, нічого не зберігається
     */
    public function save(array $post): array
    {
        $errors = $this->validate($post);
        if ($errors !== []) {
            return $errors;
        }
        $text = static fn(string $field): string => is_string($post[$field] ?? null) ? trim($post[$field]) : '';
        $this->settings->set(ShippingConfig::DEFAULT_WEIGHT, self::decimal($text('default_weight')));
        foreach (self::SIDES as $field => $setting) {
            $this->settings->set($setting, (int)$text($field));
        }
        $this->settings->set(ShippingConfig::RECEIVER_PAY, empty($post['receiver_pay']) ? 0 : 1);
        $this->settings->set(ShippingConfig::DECLARE_TOTAL, empty($post['declare_total']) ? 0 : 1);
        $this->settings->set(ShippingConfig::DECLARED_VALUE, self::decimal($text('declared_value')) ?? 0);
        $this->settings->set(ShippingConfig::DESCRIPTION, self::oneLine($text('description')));
        $this->settings->set(ShippingConfig::CUTOFF, ShippingConfig::cutoff($text('cutoff')) ?? ShippingConfig::DEFAULT_CUTOFF);
        $this->settings->set(ShippingConfig::CART_PRICE, self::decimal($text('cart_price')));
        $this->settings->set(ShippingConfig::RECEIVER_PRICE_NOTE, empty($post['receiver_price_note']) ? 0 : 1);

        return [];
    }

    /**
     * Межі — мережеві обмеження з тарифів: вага ≤ 15 кг, сторона ≤ 120 см, оголошена ≤ 30 000 грн.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public function validate(array $post): array
    {
        $text = static fn(string $field): string => is_string($post[$field] ?? null) ? trim($post[$field]) : '';
        $errors = [];
        $weight = self::decimal($text('default_weight'));
        if ($weight === null || $weight <= 0 || $weight > PointLimits::NETWORK_WEIGHT) {
            $errors['default_weight'] = 'sviat__rozetka__shipping_err_weight';
        }
        foreach (array_keys(self::SIDES) as $field) {
            $side = preg_match('/^\d{1,3}$/', $text($field)) === 1 ? (int)$text($field) : 0;
            if ($side < 1 || $side > PointLimits::NETWORK_LENGTH) {
                $errors[$field] = 'sviat__rozetka__shipping_err_side';
            }
        }
        // Фіксована оголошена потрібна лише тоді, коли не оголошується сума замовлення.
        if (empty($post['declare_total']) || $text('declared_value') !== '') {
            $declared = self::decimal($text('declared_value'));
            if ($declared === null || $declared <= 0 || $declared > PointLimits::NETWORK_COST) {
                $errors['declared_value'] = 'sviat__rozetka__shipping_err_declared_value';
            }
        }
        if (mb_strlen(self::oneLine($text('description'))) > self::DESCRIPTION_MAX) {
            $errors['description'] = 'sviat__rozetka__shipping_err_description';
        }
        if ($text('cutoff') !== '' && ShippingConfig::cutoff($text('cutoff')) === null) {
            $errors['cutoff'] = 'sviat__rozetka__shipping_err_cutoff';
        }
        $price = self::decimal($text('cart_price'));
        if ($price === null || $price > self::PRICE_MAX) {
            $errors['cart_price'] = 'sviat__rozetka__shipping_err_cart_price';
        }

        return $errors;
    }

    /**
     * Півгодинні слоти доби; збережена година поза сіткою теж лишається у списку, щоб форма її не загубила.
     *
     * @return list<string>
     */
    public static function cutoffChoices(string $current): array
    {
        $choices = array_map(static fn(int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60), range(0, 23 * 60 + 30, 30));
        $current = ShippingConfig::cutoff($current);
        if ($current !== null && !in_array($current, $choices, true)) {
            $choices[] = $current;
            sort($choices);
        }

        return $choices;
    }

    /** Число з комою чи крапкою, як вводять в українській локалі; усе інше, зокрема мінус, — null. */
    private static function decimal(string $raw): ?float
    {
        return preg_match('/^\d{1,9}(?:[.,]\d{1,3})?$/', $raw) === 1 ? (float)str_replace(',', '.', $raw) : null;
    }

    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
