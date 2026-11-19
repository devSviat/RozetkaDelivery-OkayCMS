<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Store;

/**
 * Масові записи рядків довідників. $table — 'points' або 'cities'; ключі — UUID у нижньому регістрі.
 * Rozetka віддає всю мережу одним обходом, тож одиниця заміни — уся таблиця.
 * Кожен метод кидає виняток на невдалому запиті: тихо провалений запис, а за ним deleteNotSeen(),
 * стерли б рядки, які ще дійсні.
 */
interface DictionaryStore
{
    public const string POINTS = 'points';
    public const string CITIES = 'cities';

    /** @return array<string, string> id → row_hash */
    public function hashes(string $table): array;

    /** @param list<array<string, mixed>> $rows вставлені або замінені */
    public function upsert(string $table, array $rows, int $run): void;

    /** Позначає незмінені рядки як присутні в запуску $run. @param list<string> $ids */
    public function touch(string $table, array $ids, int $run): void;

    /** Видаляє рядки, яких запуск $run не побачив; викликати лише після повного правдоподібного обходу. */
    public function deleteNotSeen(string $table, int $run): int;

    /** `cities.points` — кількість точок, що видають посилки: лише їх показує кошик. */
    public function recountPoints(): void;
}
