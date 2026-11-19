<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/** Форма ЕН, як її надіслано: лише відомі поля, текст обрізано до ліміту; решту перевіряє ParcelDraft. */
final class ParcelInput
{
    public const int NAME_MAX = 100;
    public const int DESCRIPTION_MAX = 255;

    private const array TEXT = [
        'last_name' => self::NAME_MAX,
        'first_name' => self::NAME_MAX,
        'middle_name' => self::NAME_MAX,
        'phone' => 20,
        'payer' => 16,
        'description' => self::DESCRIPTION_MAX,
    ];
    private const array NUMBERS = ['weight', 'length', 'width', 'height', 'declared_value', 'cod_amount'];

    /** @return array<string, string|float|bool> */
    public static function fromPost(array $post): array
    {
        $input = [];
        foreach (self::TEXT as $name => $limit) {
            if (is_string($post[$name] ?? null)) {
                $input[$name] = mb_substr(PersonName::clean($post[$name]), 0, $limit);
            }
        }
        foreach (self::NUMBERS as $name) {
            if (is_string($post[$name] ?? null)) {
                $raw = str_replace(',', '.', trim($post[$name]));
                // Не число лишається текстом, щоб чернетка повідомила про поле, а не зберегла старе значення.
                $input[$name] = is_numeric($raw) && is_finite((float)$raw) ? (float)$raw : mb_substr($raw, 0, 20);
            }
        }
        $input['release_unpaid'] = ($post['release_unpaid'] ?? null) === '1';

        return $input;
    }
}
