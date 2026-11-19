<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Cart;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;

/** Кнопки міст над полем міста в кошику: їх обирає й упорядковує лише менеджер. */
final class PopularCities
{
    public const int MAX = 20;
    private const string STATE = 'popular_cities';

    public function __construct(
        private readonly StateStorage $state,
        private readonly EntityFactory $entityFactory,
    ) {
    }

    /** @param list<mixed> $ids у порядку, який задав менеджер */
    public function save(array $ids): void
    {
        $wanted = self::normalize($ids);
        $existing = $wanted === [] ? [] : array_map(
            static fn(object $row): string => (string)$row->id,
            $this->entityFactory->get(CityEntity::class)->cols(['id'])->noLimit()->find(['id' => $wanted]) ?: [],
        );
        self::store($this->state, self::clean($wanted, $existing));
    }

    /** @return list<array{id:string, name:string, type:string, region:string}> */
    public function list(): array
    {
        $ids = self::stored($this->state);
        if ($ids === []) {
            return [];
        }
        $rows = [];
        foreach ($this->entityFactory->get(CityEntity::class)->cols(['id', 'name_ua', 'type', 'region_ua'])->noLimit()->find(['id' => $ids]) ?: [] as $row) {
            $rows[(string)$row->id] = $row;
        }
        $list = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $list[] = [
                    'id' => $id,
                    'name' => (string)$rows[$id]->name_ua,
                    'type' => (string)$rows[$id]->type,
                    'region' => (string)$rows[$id]->region_ua,
                ];
            }
        }

        return $list;
    }

    /** @return list<array{id:string, label:string, type:string}> кнопки для кошика */
    public function buttons(): array
    {
        return array_map(self::button(...), $this->list());
    }

    /**
     * @param array{id:string, name:string, type:string} $city
     * @return array{id:string, label:string, type:string}
     */
    public static function button(array $city): array
    {
        return ['id' => $city['id'], 'label' => $city['name'], 'type' => $city['type']];
    }

    /**
     * @param list<mixed> $raw
     * @param list<string> $existingIds
     * @return list<string>
     */
    public static function clean(array $raw, array $existingIds): array
    {
        $clean = [];
        foreach (self::normalize($raw) as $id) {
            if (in_array($id, $existingIds, true) && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        return array_slice($clean, 0, self::MAX);
    }

    /** @return list<string> */
    public static function stored(StateStorage $state): array
    {
        $ids = json_decode((string)$state->get(self::STATE), true);

        return is_array($ids) && array_is_list($ids) ? self::normalize($ids) : [];
    }

    /** @param list<string> $ids */
    public static function store(StateStorage $state, array $ids): void
    {
        $state->set(self::STATE, json_encode($ids));
    }

    /**
     * UUID міст зберігаються в нижньому регістрі; число чи порожній рядок не може бути id міста.
     *
     * @param list<mixed> $raw
     * @return list<string>
     */
    private static function normalize(array $raw): array
    {
        $ids = [];
        foreach ($raw as $id) {
            $id = is_string($id) ? strtolower(trim($id)) : '';
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
