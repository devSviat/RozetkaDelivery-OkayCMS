<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/** ПІБ одержувача з полів замовлення: ROZETKA Delivery вимагає прізвище й ім'я окремо. */
final class PersonName
{
    /**
     * Порожнє прізвище — ім'я ділиться за пробілами: два слова «Ім'я Прізвище» (так їх вводять у кошику з одним полем),
     * три — «Прізвище Ім'я По батькові». Одне слово неоднозначне.
     *
     * @return array{last: string, first: string, middle: string}|null
     */
    public static function split(string $lastName, string $name): ?array
    {
        $last = self::clean($lastName);
        $words = self::words($name);
        if ($last !== '') {
            return $words === [] ? null : ['last' => $last, 'first' => $words[0], 'middle' => implode(' ', array_slice($words, 1))];
        }

        return match (count($words)) {
            2 => ['last' => $words[1], 'first' => $words[0], 'middle' => ''],
            3 => ['last' => $words[0], 'first' => $words[1], 'middle' => $words[2]],
            default => null,
        };
    }

    public static function clean(string $value): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }

    /** @return list<string> */
    private static function words(string $value): array
    {
        $clean = self::clean($value);

        return $clean === '' ? [] : explode(' ', $clean);
    }
}
