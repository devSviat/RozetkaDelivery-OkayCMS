<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Backend;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\NameNormalizer;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;

/** Місто й точка, куди магазин здає відправлення: вибір із довідника. */
class SenderPoints
{
    public const int MIN_QUERY = 2;
    public const int CITY_LIMIT = 20;

    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    /**
     * Точки, що лише видають (`can_receive = 0`), відправлень не приймають, тож відправник їх не обирає.
     *
     * @return list<array{id:string, number:string, title:string}>
     */
    public function forCity(string $cityId): array
    {
        if ($cityId === '') {
            return [];
        }
        $rows = $this->entityFactory->get(PointEntity::class)
            ->cols(['id', 'number', 'title'])
            ->noLimit()
            ->find(['city_id' => $cityId, 'can_receive' => 1]) ?: [];

        $options = array_map(static fn(object $row): array => [
            'id' => (string)$row->id,
            'number' => (string)($row->number ?? ''),
            'title' => (string)$row->title,
        ], $rows);
        // Номер — рядок у таблиці, і SQL-сортування дало б 1, 10, 100, 2.
        usort($options, static fn(array $a, array $b): int => strnatcmp($a['number'], $b['number']) ?: strcmp($a['title'], $b['title']));

        return $options;
    }

    /** Місто точки, що приймає відправлення, або null: надісланий вручну id точки «лише видача» не проходить. */
    public function receivingCity(string $pointId): ?string
    {
        if ($pointId === '') {
            return null;
        }
        $cityId = $this->entityFactory->get(PointEntity::class)->cols(['city_id'])->findOne(['id' => $pointId, 'can_receive' => 1]);

        return is_string($cityId) && $cityId !== '' ? $cityId : null;
    }

    /** @return array{id:string, name:string, type:string, region:string}|null */
    public function city(string $cityId): ?array
    {
        $city = $cityId === '' ? false : $this->entityFactory->get(CityEntity::class)
            ->cols(['id', 'name_ua', 'type', 'region_ua'])
            ->findOne(['id' => $cityId]);

        return $city ? self::cityRow($city) : null;
    }

    /** @return list<array{id:string, name:string, type:string, region:string}> */
    public function searchCities(string $query): array
    {
        $normalized = NameNormalizer::normalize($query);
        if (mb_strlen($normalized) < self::MIN_QUERY) {
            return [];
        }
        $rows = $this->entityFactory->get(CityEntity::class)
            ->cols(['id', 'name_ua', 'type', 'region_ua'])
            ->find(['keyword' => $normalized, 'limit' => self::CITY_LIMIT]) ?: [];

        return array_values(array_map(self::cityRow(...), $rows));
    }

    /** @return array{id:string, name:string, type:string, region:string} */
    private static function cityRow(object $row): array
    {
        return [
            'id' => (string)$row->id,
            'name' => (string)$row->name_ua,
            'type' => (string)($row->type ?? ''),
            'region' => (string)($row->region_ua ?? ''),
        ];
    }
}
