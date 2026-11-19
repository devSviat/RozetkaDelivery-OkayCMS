<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Groups;

use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointKind;

/** Дані шаблону для блоку груп; прямо каже, яких точок покупці не побачать. */
final class GroupsView
{
    /**
     * @param list<object> $groups рядки GroupEntity
     * @param array<string, int> $counts точки за видами
     * @return array{groups: list<array{id:int, name:string, enabled:bool, position:int}>, kinds: list<array{key:string, label_key:string, count:int, group:int, warning:?string}>}
     */
    public static function vars(array $groups, array $counts): array
    {
        $groupVars = [];
        $owners = [];
        foreach ($groups as $group) {
            $id = (int)$group->id;
            $groupVars[] = [
                'id' => $id,
                'name' => (string)$group->name,
                'enabled' => (bool)$group->enabled,
                'position' => (int)$group->position,
            ];
            $kinds = json_decode((string)$group->kinds, true);
            foreach (is_array($kinds) ? $kinds : [] as $kind) {
                // Форма дає виду одну групу; якщо в таблиці їх кілька, вибір на сторінці показує першу.
                if (is_string($kind) && !isset($owners[$kind])) {
                    $owners[$kind] = ['id' => $id, 'enabled' => (bool)$group->enabled];
                }
            }
        }

        $kindVars = [];
        foreach (PointKind::cases() as $kind) {
            $owner = $owners[$kind->value] ?? null;
            $kindVars[] = [
                'key' => $kind->value,
                'label_key' => 'sviat__rozetka__groups_kind_' . $kind->value,
                'count' => (int)($counts[$kind->value] ?? 0),
                'group' => $owner['id'] ?? 0,
                'warning' => match (true) {
                    $owner === null => 'no_group',
                    !$owner['enabled'] => 'group_disabled',
                    default => null,
                },
            ];
        }

        return ['groups' => $groupVars, 'kinds' => $kindVars];
    }
}
