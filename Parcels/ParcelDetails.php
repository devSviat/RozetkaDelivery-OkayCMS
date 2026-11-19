<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/**
 * Знімок ЕН для картки (колонка `details`), без нових запитів: з чернетки й відповіді `POST track` або з
 * `GET track/{id}` при прив'язці. Лише білий список ключів і без телефонів; те саме очищення стоїть і на
 * читанні, тож зіпсований JSON дає порожній знімок.
 */
final class ParcelDetails
{
    private const array TEXT = [
        'recipient_name' => 3 * ParcelInput::NAME_MAX + 2,
        'point_title' => 255,
        'description' => ParcelInput::DESCRIPTION_MAX,
    ];
    private const array SIDES = ['length', 'width', 'height'];
    /** Комісія післяплати з відповіді: що означає `payment_fee_limit`, не відомо, тож лише показ. */
    private const array FEE = ['fee_percentage', 'fee_additional', 'fee_limit'];

    /**
     * @param array<mixed> $answer data відповіді `POST track`
     * @return array<string, string|int|float>
     */
    public static function fromDraft(ParcelDraft $draft, array $answer): array
    {
        $fee = is_array($answer['payment_fee_details'] ?? null) ? $answer['payment_fee_details'] : [];

        return self::clean([
            'recipient_name' => PersonName::clean("{$draft->lastName} {$draft->firstName} {$draft->middleName}"),
            'point_title' => $draft->point->title ?? null,
            'description' => $draft->description,
            'weight_kg' => $draft->weightKg,
            'length' => $draft->lengthCm,
            'width' => $draft->widthCm,
            'height' => $draft->heightCm,
            'volume' => round($draft->lengthCm * $draft->widthCm * $draft->heightCm / 1_000_000, 6),
            'fee_percentage' => $fee['percentage'] ?? null,
            'fee_additional' => $fee['additional'] ?? null,
            'fee_limit' => $fee['payment_fee_limit'] ?? null,
        ]);
    }

    /**
     * ЕН з `GET track/{id}`: прив'язана деінде, тож чернетки немає.
     *
     * @param array<mixed> $track
     * @return array<string, string|int|float>
     */
    public static function fromApi(array $track): array
    {
        $recipient = is_array($track['recipient'] ?? null) ? $track['recipient'] : [];
        $department = is_array($recipient['department'] ?? null) ? $recipient['department'] : [];
        $params = is_array($track['params'] ?? null) ? $track['params'] : [];
        $name = implode(' ', array_filter(
            [$recipient['last_name'] ?? null, $recipient['first_name'] ?? null, $recipient['middle_name'] ?? null],
            static fn(mixed $part): bool => is_string($part) && trim($part) !== '',
        ));

        return self::clean([
            'recipient_name' => PersonName::clean($name),
            'point_title' => $department['name'] ?? null,
            'description' => $track['description'] ?? null,
            'weight_kg' => $params['weight'] ?? null,
            'length' => $params['length'] ?? null,
            'width' => $params['width'] ?? null,
            'height' => $params['height'] ?? null,
            'volume' => $params['volume'] ?? null,
        ]);
    }

    /** @param array<string, string|int|float> $details */
    public static function encode(array $details): ?string
    {
        $details = self::clean($details);
        if ($details === []) {
            return null;
        }
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

        return $json === false ? null : $json;
    }

    /** @return array<string, string|int|float> */
    public static function decode(mixed $json): array
    {
        $raw = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($raw) && !array_is_list($raw) ? self::clean($raw) : [];
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, string|int|float>
     */
    private static function clean(array $raw): array
    {
        $details = [];
        foreach (self::TEXT as $key => $limit) {
            $value = is_string($raw[$key] ?? null) ? mb_substr(trim($raw[$key]), 0, $limit) : '';
            if ($value !== '') {
                $details[$key] = $value;
            }
        }
        $weight = self::number($raw['weight_kg'] ?? null);
        if ($weight !== null && $weight > 0) {
            $details['weight_kg'] = round($weight, 3);
        }
        foreach (self::SIDES as $key) {
            $value = self::number($raw[$key] ?? null);
            if ($value !== null && $value >= 1 && $value === floor($value)) {
                $details[$key] = (int)$value;
            }
        }
        $volume = self::number($raw['volume'] ?? null);
        if ($volume !== null && $volume > 0) {
            $details['volume'] = round($volume, 6);
        }
        foreach (self::FEE as $key) {
            $value = self::number($raw[$key] ?? null);
            if ($value !== null && $value >= 0) {
                $details[$key] = round($value, 2);
            }
        }

        return $details;
    }

    private static function number(mixed $value): ?float
    {
        $number = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float)$value : null;

        return $number !== null && is_finite($number) ? $number : null;
    }
}
