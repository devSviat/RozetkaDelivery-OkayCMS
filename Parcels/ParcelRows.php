<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/**
 * Де зберігаються ЕН замовлень: одна на замовлення.
 *
 * Колонки рядка — ParcelEntity; `details` — JSON ParcelDetails. Дати — `Y-m-d H:i:s` у поясі застосунку.
 */
interface ParcelRows
{
    public function forOrder(int $orderId): ?object;

    /**
     * Рядки сторінки списку замовлень одним запитом, лише з колонками рядка списку.
     *
     * @param list<int> $orderIds
     * @return array<int, object> за id замовлення
     */
    public function forOrders(array $orderIds): array;

    /** Замовлення, якому належить ЕН з цим номером (TrackNumber::normalize). */
    public function orderOfTrack(string $trackId): ?int;

    /**
     * Займає замовлення до запиту до Rozetka: унікальний order_id пропускає лише один із двох одночасних запитів.
     *
     * @return ?string `visible_id` цієї спроби (`{order_id}-{id}`); null, коли замовлення вже має рядок або резерв не записався
     */
    public function reserve(int $orderId, string $now): ?string;

    /**
     * Перетворює резерв чи невідомий рядок на ЕН. False, коли запис не вдався.
     *
     * @param array<string, mixed> $values колонки ParcelEntity
     */
    public function complete(int $orderId, array $values): bool;

    /**
     * Прив'язує ЕН, створену деінде: новий рядок або резерв чи невідомий рядок замовлення.
     * False, коли замовлення має ЕН чи номер уже зайнятий.
     *
     * @param array<string, mixed> $values колонки ParcelEntity; `created_at` діє лише для нового рядка
     */
    public function attach(int $orderId, array $values): bool;

    /** Запит до Rozetka пішов без відповіді: замовлення чекає на пошук чи прив'язку. */
    public function markUncertain(int $orderId): void;

    /** Знімає резерв; рядок ЕН лишається. */
    public function release(int $orderId): void;

    public function remove(int $orderId): void;

    /**
     * Нові значення стану ЕН (подія, група, історія, час перевірки); резерв не змінюється.
     *
     * @param array<string, mixed> $values колонки ParcelEntity
     */
    public function saveEvent(int $orderId, array $values): void;

    /**
     * Створені ЕН без фінальної групи, створені не раніше `$createdSince`; спершу ті, що найдовше не перевірялися.
     *
     * @return list<object>
     */
    public function unfinished(string $createdSince, int $limit): array;

    /**
     * Повністю видані створені ЕН з післяплатою, оплату якої Rozetka ще не підтвердила, з подією не раніше
     * `$eventSince` і не перевірені після `$checkedBefore`; спершу ті, що найдовше не перевірялися.
     *
     * @return list<object>
     */
    public function unpaidCod(string $eventSince, string $checkedBefore, int $limit): array;

    /** @param list<string> $trackIds ЕН, які Rozetka прийняла в реєстр (або null — прибрала з нього) */
    public function setReception(array $trackIds, ?int $receptionId): void;

    /** Реєстр видалено: його ЕН знову вільні. */
    public function clearReception(int $receptionId): void;

    /**
     * Створені ЕН у групі `created` без реєстру, найстаріші першими.
     *
     * @return list<object>
     */
    public function readyForReception(int $limit): array;
}
