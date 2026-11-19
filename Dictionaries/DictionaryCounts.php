<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries;

use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointKind;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;

/** Цифри довідників для адмінки. */
class DictionaryCounts
{
    public function __construct(
        private readonly EntityFactory $entityFactory,
        private readonly Database $db,
        private readonly QueryFactory $queryFactory,
    ) {
    }

    /** @return array<string, int> точки за видами; один прохід по таблиці, а не по одному на вид */
    public function byKind(): array
    {
        $select = $this->queryFactory->newSelect()
            ->cols(['kind', 'COUNT(*) AS n'])
            ->from(PointEntity::getTable())
            ->groupBy(['kind']);

        return self::fromRows($this->db->query($select) === false ? [] : $this->db->results());
    }

    public function cities(): int
    {
        return (int)$this->entityFactory->get(CityEntity::class)->count();
    }

    /**
     * @param iterable<object> $rows рядки `kind`, `n`
     * @return array<string, int> кожен вид, нуль, де немає рядка
     */
    public static function fromRows(iterable $rows): array
    {
        $counts = array_fill_keys(array_map(static fn(PointKind $kind): string => $kind->value, PointKind::cases()), 0);
        foreach ($rows as $row) {
            if (isset($counts[$row->kind])) {
                $counts[$row->kind] = (int)$row->n;
            }
        }

        return $counts;
    }
}
