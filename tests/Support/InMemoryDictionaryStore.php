<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Store\DictionaryStore;

final class InMemoryDictionaryStore implements DictionaryStore
{
    /** @var array<string, array<string, array>> */
    public array $tables = [self::POINTS => [], self::CITIES => []];
    public int $writes = 0;

    public function hashes(string $table): array
    {
        return array_map(static fn(array $row): string => $row['row_hash'], $this->tables[$table]);
    }

    public function upsert(string $table, array $rows, int $run): void
    {
        $this->writes++;
        // Як INSERT … ON DUPLICATE KEY UPDATE: колонки, яких немає в рядку, у наявного запису лишаються.
        foreach ($rows as $row) {
            $this->tables[$table][$row['id']] = array_replace($this->tables[$table][$row['id']] ?? [], $row, ['seen_run' => $run]);
        }
    }

    public function touch(string $table, array $ids, int $run): void
    {
        $this->writes++;
        foreach ($ids as $id) {
            $this->tables[$table][$id]['seen_run'] = $run;
        }
    }

    public function deleteNotSeen(string $table, int $run): int
    {
        $this->writes++;
        $before = count($this->tables[$table]);
        $this->tables[$table] = array_filter($this->tables[$table], static fn(array $row): bool => $row['seen_run'] === $run);

        return $before - count($this->tables[$table]);
    }

    public function recountPoints(): void
    {
        foreach (array_keys($this->tables[self::CITIES]) as $id) {
            $points = 0;
            foreach ($this->tables[self::POINTS] as $point) {
                if ($point['city_id'] === (string)$id && (int)$point['can_give_out'] === 1) {
                    $points++;
                }
            }
            $this->tables[self::CITIES][$id]['points'] = $points;
        }
    }
}
