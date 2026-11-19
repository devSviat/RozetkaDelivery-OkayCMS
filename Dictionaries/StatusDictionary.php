<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries;

use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;

/**
 * Словник статусів `GET track-status`: код → назва й група Rozetka. Модуль переводить групу, а не код,
 * тож новий код у відомій групі розпізнається без релізу.
 */
final class StatusDictionary
{
    private const string STATE = 'status_dictionary';

    /** @var array{codes: array<int, array{name: string, group: int}>, groups: array<int, array{name: string, main: bool}>}|null */
    private ?array $loaded = null;

    public function __construct(private readonly StateStorage $state)
    {
    }

    /**
     * @param array<mixed> $groups `data` відповіді
     * @return bool false — у відповіді немає жодного коду, збережений словник лишається
     */
    public function store(array $groups): bool
    {
        $codes = [];
        $known = [];
        foreach ($groups as $group) {
            if (!is_array($group) || !is_int($group['id'] ?? null) || !is_array($group['statuses'] ?? null)) {
                continue;
            }
            $groupId = $group['id'];
            $known[$groupId] = ['name' => is_string($group['group_name'] ?? null) ? $group['group_name'] : '', 'main' => ($group['is_main_flow'] ?? false) === true];
            foreach ($group['statuses'] as $status) {
                if (is_array($status) && is_int($status['id'] ?? null)) {
                    $codes[$status['id']] = ['name' => is_string($status['name'] ?? null) ? $status['name'] : '', 'group' => $groupId];
                }
            }
        }
        if ($codes === []) {
            return false;
        }
        $this->loaded = ['codes' => $codes, 'groups' => $known];
        $this->state->set(self::STATE, json_encode($this->loaded, JSON_UNESCAPED_UNICODE));

        return true;
    }

    public function groupOf(int $code): ?int
    {
        return $this->data()['codes'][$code]['group'] ?? null;
    }

    public function name(int $code): ?string
    {
        return $this->data()['codes'][$code]['name'] ?? null;
    }

    public function groupName(int $group): ?string
    {
        return $this->data()['groups'][$group]['name'] ?? null;
    }

    public function mainFlow(int $group): bool
    {
        return $this->data()['groups'][$group]['main'] ?? false;
    }

    public function count(): int
    {
        return count($this->data()['codes']);
    }

    /** @return array{codes: array<int, array{name: string, group: int}>, groups: array<int, array{name: string, main: bool}>} */
    private function data(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        $saved = json_decode((string)$this->state->get(self::STATE), true);
        $codes = [];
        $groups = [];
        if (is_array($saved)) {
            foreach (is_array($saved['codes'] ?? null) ? $saved['codes'] : [] as $code => $row) {
                if (is_array($row) && is_int($row['group'] ?? null) && is_string($row['name'] ?? null)) {
                    $codes[(int)$code] = ['name' => $row['name'], 'group' => $row['group']];
                }
            }
            foreach (is_array($saved['groups'] ?? null) ? $saved['groups'] : [] as $id => $row) {
                if (is_array($row) && is_string($row['name'] ?? null)) {
                    $groups[(int)$id] = ['name' => $row['name'], 'main' => ($row['main'] ?? false) === true];
                }
            }
        }

        return $this->loaded = ['codes' => $codes, 'groups' => $groups];
    }
}
