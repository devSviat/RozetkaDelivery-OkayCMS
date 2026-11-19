<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Receptions;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\ReceptionEntity;

final class ReceptionStore implements ReceptionRows
{
    private const int PAGE = 200;

    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    public function get(int $receptionId): ?object
    {
        $row = $this->entity()->findOne(['reception_id' => $receptionId]);

        return is_object($row) ? $row : null;
    }

    public function save(int $receptionId, array $values): void
    {
        $values = array_intersect_key($values, array_flip(ReceptionEntity::getFields()));
        unset($values['id'], $values['reception_id']);
        $row = $this->get($receptionId);
        $row === null
            ? $this->entity()->add($values + ['reception_id' => $receptionId])
            : $this->entity()->update((int)$row->id, $values);
    }

    public function remove(int $receptionId): void
    {
        $row = $this->get($receptionId);
        if ($row !== null) {
            $this->entity()->delete([(int)$row->id]);
        }
    }

    public function recent(string $since): array
    {
        return array_values($this->entity()->find(['created_since' => $since, 'limit' => self::PAGE]));
    }

    public function open(string $since, int $limit): array
    {
        return array_values($this->entity()->order('stale')->find(['created_since' => $since, 'not_final' => true, 'limit' => $limit]));
    }

    private function entity(): ReceptionEntity
    {
        return $this->entityFactory->get(ReceptionEntity::class);
    }
}
