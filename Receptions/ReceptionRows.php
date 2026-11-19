<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Receptions;

/** Реєстри передачі, сформовані з магазину; ключ — номер реєстру Rozetka. Колонки — ReceptionEntity. */
interface ReceptionRows
{
    public function get(int $receptionId): ?object;

    /** @param array<string, mixed> $values колонки ReceptionEntity; рядок створюється, якщо його немає */
    public function save(int $receptionId, array $values): void;

    public function remove(int $receptionId): void;

    /** @return list<object> створені не раніше `$since`, новіші першими */
    public function recent(string $since): array;

    /** @return list<object> незакриті, створені не раніше `$since`; спершу ті, що найдовше не перевірялися */
    public function open(string $since, int $limit): array;
}
