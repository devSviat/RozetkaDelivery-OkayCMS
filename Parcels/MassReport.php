<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/** Що масова дія зробила з кожним замовленням; зберігається в сесії, доки список його не покаже. */
final readonly class MassReport
{
    private const array KINDS = ['created', 'skipped', 'failed'];
    private const string KEY = 'sviat__rozetka__';

    /**
     * @param list<array{orderId: int, kind: 'created'|'skipped'|'failed', key: ?string, value: ?string}> $rows
     *     key: повний мовний ключ причини; value: номер ЕН або текст Rozetka
     * @param int $untouched вибрані замовлення, лишені на наступний запуск (ліміт або бюджет часу)
     */
    public function __construct(
        public array $rows,
        public int $untouched,
    ) {
    }

    public function created(): int
    {
        return count(array_filter($this->rows, static fn(array $row): bool => $row['kind'] === 'created'));
    }

    public function processed(): int
    {
        return count($this->rows);
    }

    public function toArray(): array
    {
        return ['rows' => $this->rows, 'untouched' => $this->untouched];
    }

    /** @return ?self null для всього, що записав не цей клас */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !is_array($data['rows'] ?? null) || !is_int($data['untouched'] ?? null)) {
            return null;
        }
        $rows = [];
        foreach ($data['rows'] as $row) {
            $key = is_array($row) ? ($row['key'] ?? null) : null;
            if (!is_array($row) || !is_int($row['orderId'] ?? null) || !in_array($row['kind'] ?? null, self::KINDS, true)
                || !($key === null || (is_string($key) && str_starts_with($key, self::KEY))) || !self::nullableString($row['value'] ?? null)) {
                return null;
            }
            $rows[] = ['orderId' => $row['orderId'], 'kind' => $row['kind'], 'key' => $key, 'value' => $row['value'] ?? null];
        }

        return new self($rows, $data['untouched']);
    }

    private static function nullableString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }
}
