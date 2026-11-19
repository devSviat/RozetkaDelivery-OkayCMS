<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Store;

use Aura\SqlQuery\QueryInterface;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;

/** Масові записи через QueryFactory: порядкові записи через Entity на тисячу точок — тисяча запитів. */
final class DbDictionaryStore implements DictionaryStore
{
    public function __construct(
        private readonly Database $db,
        private readonly QueryFactory $queryFactory,
    ) {
    }

    public function hashes(string $table): array
    {
        $this->run($this->queryFactory->newSelect()->cols(['id', 'row_hash'])->from(self::table($table)));
        $hashes = [];
        foreach ($this->db->results() as $row) {
            $hashes[(string)$row->id] = (string)$row->row_hash;
        }

        return $hashes;
    }

    public function upsert(string $table, array $rows, int $run): void
    {
        if ($rows === []) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $rows = array_map(static fn(array $row): array => ['seen_run' => $run, 'updated_at' => $now] + $row, $rows);

        $insert = $this->queryFactory->newInsert()->into(self::table($table))->addRows($rows);
        foreach (array_keys($rows[0]) as $column) {
            if ($column !== 'id') {
                $insert->onDuplicateKeyUpdate($column, "VALUES({$column})");
            }
        }
        // Aura addRows() добудовує останній рядок пакета лише під час збирання тексту, а Database::query()
        // OkayCMS 4.5.3 читає прив'язки раніше: пакет із двох рядків губив значення другого.
        $insert->getStatement();
        $this->run($insert);
    }

    public function touch(string $table, array $ids, int $run): void
    {
        if ($ids === []) {
            return;
        }
        $update = $this->queryFactory->newUpdate()->table(self::table($table))
            ->set('seen_run', ':run')->where('id IN (:ids)')
            ->bindValues(['run' => $run, 'ids' => $ids]);
        $this->run($update);
    }

    public function deleteNotSeen(string $table, int $run): int
    {
        $delete = $this->queryFactory->newDelete()->from(self::table($table))
            ->where('seen_run <> :run')->bindValue('run', $run);
        $this->run($delete);

        return (int)$this->db->affectedRows();
    }

    public function recountPoints(): void
    {
        $points = PointEntity::getTable();
        $cities = CityEntity::getTable();
        $update = $this->queryFactory->newUpdate()->table($cities)
            ->set('points', "(SELECT COUNT(*) FROM {$points} p WHERE p.city_id = {$cities}.id AND p.can_give_out = 1)");
        $this->run($update);
    }

    /** Database::query() логує й ковтає помилки SQL; запуск довідників натомість мусить зупинитися. */
    private function run(QueryInterface $query): void
    {
        if ($this->db->query($query) === false) {
            throw new \RuntimeException('Rozetka dictionary query failed; see the application log');
        }
    }

    private static function table(string $table): string
    {
        return match ($table) {
            self::POINTS => PointEntity::getTable(),
            self::CITIES => CityEntity::getTable(),
            default => throw new \InvalidArgumentException("Unknown dictionary table {$table}"),
        };
    }
}
