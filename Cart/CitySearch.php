<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Cart;

use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\NameNormalizer;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;

/** Підказки міст для кошика: з власної таблиці модуля, ніколи не запитом до Rozetka. */
final class CitySearch
{
    private const int MAX_WORDS = 5;
    private const int MAX_LENGTH = 64;
    /** Одна літера збігається з більшою частиною таблиці, а запит публічний. */
    private const int MIN_LENGTH = 2;
    /** Сам лише тип населеного пункту збігається з кожним і нічого корисного не знаходить. */
    private const array TYPE_WORDS = ['місто', 'село', 'селище', 'смт'];

    public function __construct(
        private readonly Database $db,
        private readonly QueryFactory $queryFactory,
    ) {
    }

    /**
     * Лише міста з хоча б однією точкою видачі, який покаже кошик. Кожне слово шукається з початку слова
     * `search_text`: «гадане» не повинне знаходити «Вигадане».
     *
     * @return list<array{id:string, name:string, type:string, district:string, region:string}>
     */
    public function find(string $raw, int $limit = 20): array
    {
        $words = self::words($raw);
        if ($words === [] || mb_strlen(implode('', $words)) < self::MIN_LENGTH) {
            return [];
        }

        $select = $this->queryFactory->newSelect()
            ->cols(['c.id', 'c.name_ua', 'c.type', 'c.district_ua', 'c.region_ua'])
            ->from(CityEntity::getTable() . ' AS c')
            ->where('c.points > 0')
            ->limit($limit);
        foreach ($words as $i => $word) {
            $select->where("(c.search_text LIKE :s{$i} OR c.search_text LIKE :m{$i})")
                ->bindValues(["s{$i}" => $word . '%', "m{$i}" => '% ' . $word . '%']);
        }
        // search_text починається з власної назви, тож збіг за префіксом — це збіг назви.
        $select->orderBy(['(c.search_text LIKE :prefix) DESC', 'c.points DESC', 'c.name_ua', 'c.id'])
            ->bindValue('prefix', $words[0] . '%');

        return $this->db->query($select) === false ? [] : self::present($this->db->results());
    }

    /**
     * Тезок розрізняє район і область, а вибір іде за UUID: назва в місті не унікальна.
     *
     * @param list<object> $rows
     * @return list<array{id:string, name:string, type:string, district:string, region:string}>
     */
    public static function present(array $rows): array
    {
        return array_map(static fn(object $row): array => [
            'id' => (string)$row->id,
            'name' => (string)$row->name_ua,
            'type' => (string)($row->type ?? ''),
            'district' => (string)($row->district_ua ?? ''),
            'region' => (string)($row->region_ua ?? ''),
        ], $rows);
    }

    /** @return list<string> слова в тій формі, в якій зберігається `search_text`, екрановані для LIKE */
    public static function words(string $raw): array
    {
        $raw = preg_replace('/\p{Cf}+/u', '', $raw) ?? '';
        $normalized = mb_substr(NameNormalizer::normalize($raw), 0, self::MAX_LENGTH);
        $words = array_values(array_filter(
            explode(' ', $normalized),
            static fn(string $word): bool => $word !== '' && !in_array($word, self::TYPE_WORDS, true),
        ));

        return array_map(static fn(string $w): string => addcslashes($w, '\\%_'), array_slice($words, 0, self::MAX_WORDS));
    }
}
