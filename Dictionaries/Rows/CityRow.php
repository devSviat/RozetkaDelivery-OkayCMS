<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows;

/** Населений пункт з `location` точки: окремий довідник /api/city — це весь КАТОТТГ, а не міста з точками. */
final readonly class CityRow
{
    /** «м. Київ», «с. Бокиївка», «смт Умовне»: тип — перше слово з крапкою або відоме скорочення без неї. */
    private const string TYPE = '/^((?:\p{L}+[.-])+(?:\p{L}+)?|смт)\s+(.+)$/u';

    public function __construct(
        public string $id,
        public ?string $regionId,
        public ?string $districtId,
        public ?string $type,
        public string $name,
        public ?string $district,
        public ?string $region,
    ) {
    }

    /** @param array<string, mixed> $location */
    public static function fromPoint(array $location): ?self
    {
        $id = self::uuid($location['city_id'] ?? null);
        if ($id === null) {
            return null;
        }
        $full = self::text($location['city_name'] ?? null, 160) ?? '';
        [$type, $name] = preg_match(self::TYPE, $full, $m) === 1 ? [mb_substr($m[1], 0, 16), $m[2]] : [null, $full];

        return new self(
            $id,
            self::uuid($location['region_id'] ?? null),
            self::uuid($location['district_id'] ?? null),
            $type,
            mb_substr($name, 0, 128),
            self::text($location['district_name'] ?? null, 128),
            self::text($location['region_name'] ?? null, 128),
        );
    }

    /**
     * Без `points`: upsert не повинен обнуляти лічильник наявного міста до перерахунку; нове місто бере типове 0 колонки.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return RowHash::with([
            'id' => $this->id,
            'region_id' => $this->regionId,
            'district_id' => $this->districtId,
            'type' => $this->type,
            'name_ua' => $this->name,
            'district_ua' => $this->district,
            'region_ua' => $this->region,
            'search_text' => NameNormalizer::searchText($this->name, []),
        ]);
    }

    private static function uuid(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return $value === '' || strlen($value) > 36 ? null : $value;
    }

    private static function text(mixed $value, int $length): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
