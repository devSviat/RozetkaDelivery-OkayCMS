<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries;

use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Store\DictionaryStore;

/** Замінює рядки таблиці: записує пакетами лише змінені рядки, решту позначає побаченими, і рахує, що сталося. */
final class TableLoader
{
    private const int BATCH = 500;

    /** @var array<string, string> */
    private array $hashes;
    /** @var list<array<string, mixed>> */
    private array $pendingRows = [];
    /** @var list<string> */
    private array $pendingTouch = [];
    private int $added = 0;
    private int $changed = 0;
    private int $unchanged = 0;

    public function __construct(
        private readonly DictionaryStore $store,
        private readonly string $table,
        private readonly int $run,
    ) {
        $this->hashes = $store->hashes($table);
    }

    public function stored(): int
    {
        return count($this->hashes);
    }

    /** @param array<string, mixed> $row */
    public function put(array $row): void
    {
        $id = (string)$row['id'];
        $known = $this->hashes[$id] ?? null;
        if ($known === $row['row_hash']) {
            $this->unchanged++;
            $this->pendingTouch[] = $id;
            if (count($this->pendingTouch) >= self::BATCH) {
                $this->flushTouch();
            }

            return;
        }

        $known === null ? $this->added++ : $this->changed++;
        $this->hashes[$id] = (string)$row['row_hash'];
        $this->pendingRows[] = $row;
        if (count($this->pendingRows) >= self::BATCH) {
            $this->flushRows();
        }
    }

    /** Видаляє рядки, яких цей обхід не бачив: викликати лише після повного правдоподібного обходу. */
    public function finish(): UpdateCounts
    {
        $this->flushRows();
        $this->flushTouch();
        $deleted = $this->store->deleteNotSeen($this->table, $this->run);

        return new UpdateCounts($this->added, $this->changed, $deleted, $this->unchanged);
    }

    private function flushRows(): void
    {
        if ($this->pendingRows !== []) {
            $this->store->upsert($this->table, $this->pendingRows, $this->run);
            $this->pendingRows = [];
        }
    }

    private function flushTouch(): void
    {
        if ($this->pendingTouch !== []) {
            $this->store->touch($this->table, $this->pendingTouch, $this->run);
            $this->pendingTouch = [];
        }
    }
}
