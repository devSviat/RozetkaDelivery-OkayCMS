<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows;

/** Запис `GET department` як рядок таблиці точок. */
final readonly class PointRow
{
    public const int PARTNER_TYPE = 14;
    /** «(тільки передоплата)» чи «(тільки передплата)» у назві — єдина ознака, крім типу; окремого поля API не має. */
    private const string PREPAY_WORDS = '/передо?плат|передплат/iu';
    private const string NUMBER = '/№?\s?(\d{4,6})/u';

    public function __construct(
        public string $id,
        public string $cityId,
        public ?string $number,
        public PointKind $kind,
        public int $typeId,
        public string $title,
        public ?string $publicName,
        public string $address,
        public ?float $lat,
        public ?float $lng,
        public string $schedule,
        public ?string $extraSchedule,
        public PointLimits $limits,
        public bool $canReceive,
        public bool $canGiveOut,
        public bool $prepayOnly,
        public int $run,
    ) {
    }

    /**
     * Лише перелічені поля: коментар, перевізник і службові прапорці з відповіді не переносяться.
     *
     * @param array<string, mixed> $d запис `GET department`
     * @return ?self null, коли немає id точки чи міста
     */
    public static function fromApi(array $d, int $run): ?self
    {
        $location = is_array($d['location'] ?? null) ? $d['location'] : [];
        $id = self::uuid($d['id'] ?? null);
        $cityId = self::uuid($location['city_id'] ?? null);
        if ($id === null || $cityId === null) {
            return null;
        }
        $type = is_array($d['department_type'] ?? null) ? ($d['department_type']['id'] ?? null) : null;
        $typeId = is_int($type) || (is_string($type) && ctype_digit($type)) ? (int)$type : 0;
        $title = self::text($d['name'] ?? null, 255) ?? '';

        return new self(
            $id,
            $cityId,
            preg_match(self::NUMBER, $title, $m) === 1 ? $m[1] : null,
            PointKind::fromTypeId($typeId),
            $typeId,
            $title,
            self::text($d['public_name'] ?? null, 255),
            mb_substr(trim((self::text($location['street_name'] ?? null, 200) ?? '') . ' ' . (self::text($location['house'] ?? null, 50) ?? '')), 0, 255),
            self::coordinate($d['latitude'] ?? null),
            self::coordinate($d['longitude'] ?? null),
            json_encode(array_values(array_filter(is_array($d['schedule'] ?? null) ? $d['schedule'] : [], is_string(...))), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            is_array($d['extra_schedule'] ?? null) && $d['extra_schedule'] !== [] ? json_encode(array_values($d['extra_schedule']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            PointLimits::fromApi(is_array($d['limitations'] ?? null) ? $d['limitations'] : null),
            ($d['can_receive_tracks'] ?? false) === true,
            ($d['can_give_out_tracks'] ?? false) === true,
            self::prepayOnly($typeId, $title),
            $run,
        );
    }

    /** Обидві ознаки через OR: помилково заборонена післяплата дешевша за відмову в точці й повернення. */
    public static function prepayOnly(int $typeId, string $name): bool
    {
        return $typeId === self::PARTNER_TYPE || preg_match(self::PREPAY_WORDS, $name) === 1;
    }

    /** Тип і назва кажуть різне: сигнал, що Rozetka змінила формат довідника. */
    public function prepayMismatch(): bool
    {
        return ($this->typeId === self::PARTNER_TYPE) !== (preg_match(self::PREPAY_WORDS, $this->title) === 1);
    }

    /** @return array<string, mixed> колонки таблиці без seen_run і updated_at — їх додає сховище */
    public function columns(): array
    {
        return RowHash::with([
            'id' => $this->id,
            'city_id' => $this->cityId,
            'number' => $this->number,
            'kind' => $this->kind->value,
            'type_id' => $this->typeId,
            'title' => $this->title,
            'public_name' => $this->publicName,
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'schedule' => $this->schedule,
            'extra_schedule' => $this->extraSchedule,
            'max_weight' => $this->limits->maxWeight,
            'max_volume_weight' => $this->limits->maxVolumeWeight,
            'max_length' => $this->limits->maxLength,
            'max_cost' => $this->limits->maxCost,
            'can_receive' => (int)$this->canReceive,
            'can_give_out' => (int)$this->canGiveOut,
            'prepay_only' => (int)$this->prepayOnly,
        ]);
    }

    private static function coordinate(mixed $value): ?float
    {
        return is_numeric($value) && abs((float)$value) <= 180 && (float)$value !== 0.0 ? (float)$value : null;
    }

    private static function uuid(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return $value === '' || strlen($value) > 36 ? null : $value;
    }

    private static function text(mixed $value, int $length): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string)$value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
