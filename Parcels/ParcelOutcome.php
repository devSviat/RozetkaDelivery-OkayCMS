<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/** Відповідь на створення, пошук, прив'язку чи видалення ЕН від менеджера. */
final readonly class ParcelOutcome
{
    /**
     * @param list<ParcelIssue> $issues
     * @param list<array{track_id: string, date: ?string, receiver: ?string, cost: ?float}> $candidates
     *        ЕН, з яких менеджер вибирає, коли пошук знайшов кілька
     * @param ?string $value показується поруч із відмовою (номер ЕН, id замовлення)
     */
    public function __construct(
        public bool $ok,
        public ?string $trackId = null,
        public array $issues = [],
        public array $candidates = [],
        public ?string $value = null,
    ) {
    }

    /** @param list<ParcelIssue> $issues */
    public static function failed(array $issues, ?string $value = null): self
    {
        return new self(false, null, $issues, [], $value);
    }

    public static function refused(string $key, ?string $value = null): self
    {
        return self::failed([new ParcelIssue(null, $key)], $value);
    }
}
