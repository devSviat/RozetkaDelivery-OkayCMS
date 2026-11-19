<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Quote;

/**
 * Те, що модуль пам'ятає про відвідувача в PHP-сесії: обрану точку й ліміти публічних маршрутів кошика.
 * Лише прості масиви: сесія не повинна тримати об'єкти модуля, форма яких може змінитися.
 */
final class QuoteSession
{
    private const string SLOT = 'sviat__rozetka__cart';
    /** Пошук публічний: сесія може зробити щонайбільше стільки запитів за вікно. */
    private const int SEARCH_LIMIT = 60;
    private const int SEARCH_WINDOW = 60;

    /** @var array<string, mixed> */
    private array $memory = [];

    public function __construct(private readonly bool $phpSession = true)
    {
    }

    /** Сесія лише в пам'яті об'єкта: для тестів і запитів без кошика. */
    public static function inMemory(): self
    {
        return new self(false);
    }

    /** Одна мережева дата на сесію за `$gap` секунд; кеш дат цього ліміту не витрачає. */
    public function allowDate(int $now, int $gap): bool
    {
        $bag = $this->bag();
        $last = $bag['date_at'] ?? null;
        if (is_int($last) && $now - $last < $gap && $now >= $last) {
            return false;
        }
        $bag['date_at'] = $now;
        $this->store($bag);

        return true;
    }

    public function allowSearch(int $now): bool
    {
        $bag = $this->bag();
        $window = is_array($bag['search'] ?? null) ? $bag['search'] : [];
        $start = is_int($window['start'] ?? null) ? $window['start'] : 0;
        $count = is_int($window['count'] ?? null) ? $window['count'] : 0;
        if ($now - $start >= self::SEARCH_WINDOW || $now < $start) {
            [$start, $count] = [$now, 0];
        }
        $allowed = $count < self::SEARCH_LIMIT;
        $bag['search'] = ['start' => $start, 'count' => $allowed ? $count + 1 : $count];
        $this->store($bag);

        return $allowed;
    }

    public function choose(int $deliveryId, string $pointId): void
    {
        $bag = $this->bag();
        $chosen = is_array($bag['chosen'] ?? null) ? $bag['chosen'] : [];
        $chosen[$deliveryId] = $pointId;
        $bag['chosen'] = $chosen;
        $this->store($bag);
    }

    /** UUID точки, обраної для способу в цій сесії. */
    public function chosen(int $deliveryId): ?string
    {
        $bag = $this->bag();
        $pointId = is_array($bag['chosen'] ?? null) ? ($bag['chosen'][$deliveryId] ?? null) : null;

        return is_string($pointId) && $pointId !== '' ? $pointId : null;
    }

    /** @return array<string, mixed> */
    private function bag(): array
    {
        if (!$this->phpSession) {
            return $this->memory;
        }

        return is_array($_SESSION[self::SLOT] ?? null) ? $_SESSION[self::SLOT] : [];
    }

    /** @param array<string, mixed> $bag */
    private function store(array $bag): void
    {
        if ($this->phpSession) {
            $_SESSION[self::SLOT] = $bag;
        } else {
            $this->memory = $bag;
        }
    }
}
