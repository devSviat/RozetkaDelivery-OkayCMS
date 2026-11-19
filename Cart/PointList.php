<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Cart;

use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Core\Settings;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsStore;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;

/**
 * Точки одного міста для кошика: вкладки груп із кількістю й одна сторінка обраної групи.
 * Місто тягне всі свої точки одним запитом: їх десятки, тож пошук і порядок рахуються тут.
 */
final class PointList
{
    public const int PAGE = 100;
    private const int MAX_SEARCH = 64;
    private const int MAX_WORDS = 5;
    private const array COLUMNS = [
        'p.id', 'p.city_id', 'p.number', 'p.kind', 'p.type_id', 'p.title', 'p.address', 'p.schedule', 'p.extra_schedule',
        'p.max_weight', 'p.max_volume_weight', 'p.max_length', 'p.max_cost', 'p.can_give_out', 'p.prepay_only',
    ];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly Database $db,
        private readonly QueryFactory $queryFactory,
        private readonly GroupsStore $groups,
        private readonly Settings $settings,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return array{groups: list<array{id:int, name:string, count:int}>, group: ?int, points: list<array<string, mixed>>} */
    public function forCity(string $cityId, CartMetrics $cart, bool $cod, ?int $groupId = null, string $query = ''): array
    {
        $select = $this->queryFactory->newSelect()
            ->cols(self::COLUMNS)
            ->from(PointEntity::getTable() . ' AS p')
            ->where('p.city_id = :city')
            ->where('p.can_give_out = 1')
            ->bindValue('city', $cityId);
        $points = $this->db->query($select) === false ? [] : $this->db->results();

        return self::build(
            $points,
            $this->groups->all(),
            $cart,
            ShippingConfig::fromSettings($this->settings->get(...)),
            $cod,
            self::today(($this->clock)()),
            $groupId,
            $query,
        );
    }

    /** Дата в Києві: окремі дні розкладу Rozetka задає календарними датами. */
    public static function today(int $now): string
    {
        return new \DateTimeImmutable('@' . $now)->setTimezone(new \DateTimeZone('Europe/Kyiv'))->format('Y-m-d');
    }

    /**
     * @param list<object> $points рядки PointEntity з колонками COLUMNS
     * @param list<object> $groupRows рядки GroupEntity
     * @return array{groups: list<array{id:int, name:string, count:int}>, group: ?int, points: list<array<string, mixed>>}
     */
    public static function build(array $points, array $groupRows, CartMetrics $cart, ShippingConfig $box, bool $cod, string $today, ?int $groupId, string $query): array
    {
        $points = array_values(array_filter($points, static fn(object $p): bool => (int)($p->can_give_out ?? 0) === 1));
        $counts = [];
        foreach ($points as $point) {
            $counts[$point->kind] = ($counts[$point->kind] ?? 0) + 1;
        }
        $groups = self::groupCounts($groupRows, $counts);
        $ids = array_column($groups, 'id');
        $chosen = in_array($groupId, $ids, true) ? $groupId : ($ids[0] ?? null);
        if ($chosen === null) {
            return ['groups' => $groups, 'group' => null, 'points' => []];
        }

        // Номер — рядок: у SQL порядок був би 1, 10, 100, 2.
        usort($points, static fn(object $a, object $b): int => strnatcmp((string)$a->number, (string)$b->number) ?: strcmp((string)$a->id, (string)$b->id));
        $kinds = self::kindsOf($groupRows, $chosen);
        $words = self::searchWords($query);
        $ranked = [];
        foreach ($points as $point) {
            if (!in_array($point->kind, $kinds, true)) {
                continue;
            }
            $rank = $words === null ? 0 : self::matchRank($point, $words);
            if ($rank === null) {
                continue;
            }
            $problems = PointFit::problems($cart, $box, $cod, $point);
            $ranked[$rank][$problems === [] ? 0 : 1][] = [$point, $problems, $cod ? $problems : PointFit::problems($cart, $box, true, $point)];
        }
        ksort($ranked);

        $list = [];
        foreach ($ranked as $parts) {
            foreach ([...($parts[0] ?? []), ...($parts[1] ?? [])] as [$point, $problems, $codProblems]) {
                $list[] = self::point($point, $problems, $codProblems, $today);
            }
        }

        return ['groups' => $groups, 'group' => $chosen, 'points' => array_slice($list, 0, self::PAGE)];
    }

    /**
     * Проблеми з післяплатою йдуть поруч: скрипт кошика сірить точки, щойно покупець обере спосіб оплати
     * з позначкою, без нового запиту. Це лише зручність — оформлення перевіряє сервер.
     *
     * @param list<FitProblem> $problems
     * @param list<FitProblem> $codProblems
     * @return array{id:string, number:string, kind:string, title:string, address:string, schedule:array, prepay_only:bool, disabled:bool, problems:list<string>, cod_problems:list<string>}
     */
    public static function point(object $point, array $problems, array $codProblems, string $today): array
    {
        return [
            'id' => (string)$point->id,
            'number' => (string)($point->number ?? ''),
            'kind' => (string)$point->kind,
            'title' => (string)$point->title,
            'address' => (string)($point->address ?? ''),
            'schedule' => WorkSchedule::fromColumns($point->schedule ?? null, $point->extra_schedule ?? null, $today),
            'prepay_only' => (int)($point->prepay_only ?? 0) === 1,
            'disabled' => $problems !== [],
            'problems' => array_map(static fn(FitProblem $p): string => $p->value, $problems),
            'cod_problems' => array_map(static fn(FitProblem $p): string => $p->value, $codProblems),
        ];
    }

    /**
     * @param list<object> $groupRows рядки GroupEntity
     * @param array<string, int> $counts точки за видами
     * @return list<array{id:int, name:string, count:int}> групи, які покупець може обрати, по порядку
     */
    public static function groupCounts(array $groupRows, array $counts): array
    {
        $groups = [];
        foreach ($groupRows as $group) {
            $count = 0;
            foreach (self::kindsOf([$group], (int)$group->id) as $kind) {
                $count += $counts[$kind] ?? 0;
            }
            if ($count > 0) {
                $groups[] = ['id' => (int)$group->id, 'name' => (string)$group->name, 'count' => $count];
            }
        }

        return $groups;
    }

    /**
     * @param list<object> $groupRows
     * @return list<string> види, показані в увімкненій групі
     */
    public static function kindsOf(array $groupRows, ?int $groupId): array
    {
        foreach ($groupRows as $group) {
            if ((int)$group->id !== $groupId || !(int)$group->enabled) {
                continue;
            }
            $kinds = json_decode((string)$group->kinds, true);

            return is_array($kinds) ? array_values(array_filter($kinds, is_string(...))) : [];
        }

        return [];
    }

    /**
     * @param list<object> $groupRows
     * @return list<string> види всіх увімкнених груп
     */
    public static function visibleKinds(array $groupRows): array
    {
        $kinds = [];
        foreach ($groupRows as $group) {
            array_push($kinds, ...self::kindsOf($groupRows, (int)$group->id));
        }

        return array_values(array_unique($kinds));
    }

    /**
     * Введений пошук як слова, щонайбільше 5. "№" і коми лише розділяють: "№ 10036" — це точка 10036.
     * @return ?non-empty-list<string>
     */
    public static function searchWords(string $raw): ?array
    {
        $raw = str_replace(['’', 'ʼ', '`', '‘'], "'", $raw);
        $text = mb_substr(trim(preg_replace('/[\s,№#]+/u', ' ', $raw) ?? ''), 0, self::MAX_SEARCH);
        $words = array_slice(array_values(array_filter(explode(' ', $text), static fn(string $w): bool => $w !== '')), 0, self::MAX_WORDS);

        return $words === [] ? null : $words;
    }

    /**
     * Кожне слово має знайтися в номері чи адресі ("Вигадана 5"). Порядок: точний номер, номери з цього
     * початку, решта. Null — точка не підходить.
     *
     * @param non-empty-list<string> $words
     */
    private static function matchRank(object $point, array $words): ?int
    {
        $number = (string)($point->number ?? '');
        $haystack = (string)($point->address ?? '') . ' ' . (string)$point->title;
        foreach ($words as $word) {
            if (!self::wordMatches($word, $number, $haystack)) {
                return null;
            }
        }

        return match (true) {
            $number === $words[0] => 0,
            ctype_digit($words[0]) && str_starts_with($number, $words[0]) => 1,
            default => 2,
        };
    }

    /** Число — початок номера точки або будинок в адресі з початку числа: "2" не знаходить будинок 12. */
    private static function wordMatches(string $word, string $number, string $haystack): bool
    {
        if (!ctype_digit($word)) {
            return mb_stripos($haystack, $word) !== false;
        }

        return str_starts_with($number, $word) || preg_match('/(?<!\d)' . $word . '/', $haystack) === 1;
    }
}
