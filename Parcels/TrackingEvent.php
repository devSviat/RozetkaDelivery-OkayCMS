<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/** Статус ЕН з `track/status` чи `track/{id}`: `{status, date, status_name}`. Нечитабельний — null, до історії не потрапляє. */
final readonly class TrackingEvent
{
    public const int TEXT_LENGTH = 255;

    /**
     * @param ?int $code код статусу; null — рядок схеми без відомого коду
     * @param string $raw статус як прийшов (`40030` чи `acceptedInDepartment`)
     * @param string $at `Y-m-d H:i:s` у поясі застосунку
     */
    public function __construct(
        public ?int $code,
        public string $raw,
        public string $at,
        public string $name,
    ) {
    }

    public static function fromApi(mixed $e): ?self
    {
        if (!is_array($e)) {
            return null;
        }
        $status = $e['status'] ?? null;
        $raw = is_int($status) ? (string)$status : (is_string($status) ? trim($status) : '');
        $at = KyivTime::toLocal($e['date'] ?? null);
        if ($raw === '' || mb_strlen($raw) > 64 || $at === null) {
            return null;
        }
        $name = is_string($e['status_name'] ?? null) ? trim($e['status_name']) : '';

        return new self(ParcelStatus::code($raw), $raw, $at, mb_substr($name, 0, self::TEXT_LENGTH));
    }

    /** Статус для ParcelStatus: число, коли воно відоме, інакше рядок. */
    public function status(): int|string
    {
        return $this->code ?? $this->raw;
    }
}
