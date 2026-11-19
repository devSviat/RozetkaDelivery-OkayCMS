<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Quote;

use Okay\Core\Settings;
use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RequestBudget;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;

/**
 * Орієнтовна дата доставки в точку з публічного `GET track/estimated-delivery-date`. Кошик без дати
 * працює так само, тож будь-яка помилка — null, а не виняток. Покупець не впливає ні на що в запиті,
 * крім UUID точки.
 */
final class DeliveryDate
{
    private const string PATH = 'track/estimated-delivery-date';
    private const string CACHE = 'date_cache';
    private const int CACHE_TTL = 3600;
    /** Кеш спільний для всіх відвідувачів: точок близько тисячі, а записи старші за TTL відкидаються. */
    private const int CACHE_MAX = 500;
    public const int SESSION_GAP = 10;
    private const int TIMEOUT = 5;
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly RozetkaClient $client,
        private readonly StateStorage $state,
        private readonly Settings $settings,
        private readonly QuoteSession $session,
        /** @var \Closure(): int */
        private readonly \Closure $now,
    ) {
    }

    /** @return ?string Y-m-d; null — дати немає, і кошик її не показує */
    public function estimate(string $pointId): ?string
    {
        $config = ShippingConfig::fromSettings($this->settings->get(...));
        if ($config->senderPointId === '' || preg_match(self::UUID, $pointId) !== 1) {
            return null;
        }
        $now = ($this->now)();
        $day = SendingDate::for($now, $config->cutoff);
        $key = $pointId . '|' . $day;
        $cache = $this->cache($now);
        if (array_key_exists($key, $cache)) {
            return $cache[$key]['date'];
        }
        if (!$this->session->allowDate($now, self::SESSION_GAP)) {
            return null;
        }

        [$date, $settled] = $this->ask($config, $pointId, $day);
        if ($settled) {
            $cache[$key] = ['date' => $date, 'at' => $now];
            $this->state->set(self::CACHE, json_encode(array_slice($cache, -self::CACHE_MAX, null, true)));
        }

        return $date;
    }

    /** Лише з кешу, без запиту: оформлення замовлення не повинне чекати на API. */
    public function cached(string $pointId): ?string
    {
        $config = ShippingConfig::fromSettings($this->settings->get(...));
        if ($config->senderPointId === '' || preg_match(self::UUID, $pointId) !== 1) {
            return null;
        }
        $now = ($this->now)();

        return $this->cache($now)[$pointId . '|' . SendingDate::for($now, $config->cutoff)]['date'] ?? null;
    }

    /**
     * @return array{0: ?string, 1: bool} дата й чи відповідь варто пам'ятати: так — дата, відповідь без дати
     *     або 500 (для частини точок API стабільно відповідає 500); ні — таймаут, 429 та інші тимчасові збої
     */
    private function ask(ShippingConfig $config, string $pointId, string $day): array
    {
        // Сервер порівнює send_date з київським часом; день, що вже минув для нього, — один повтор на завтра.
        foreach ([$day, new \DateTimeImmutable($day)->modify('+1 day')->format('Y-m-d')] as $attempt => $sendDay) {
            try {
                $data = $this->client->quick(self::TIMEOUT)->get(self::PATH, [
                    'sender_department' => $config->senderPointId,
                    'recipient_department' => $pointId,
                    'send_date' => SendingDate::sendAt($sendDay, $config->cutoff),
                ], auth: false, slot: RequestBudget::CART)['data'];

                return [self::day(is_array($data) ? ($data['delivery_date'] ?? null) : null), true];
            } catch (RozetkaApiException $e) {
                if ($attempt > 0 || $e->kind !== ApiErrorKind::Rejected || $e->field !== 'send_date') {
                    return [null, $e->kind === ApiErrorKind::ServerError];
                }
            }
        }

        return [null, false];
    }

    private static function day(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', trim($value), $m) !== 1) {
            return null;
        }

        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    /** @return array<string, array{date: ?string, at: int}> свіжі записи; null — точка без дати */
    private function cache(int $now): array
    {
        $raw = json_decode((string)$this->state->get(self::CACHE), true);
        $fresh = [];
        foreach (is_array($raw) ? $raw : [] as $key => $row) {
            if (is_string($key) && is_array($row) && array_key_exists('date', $row) && ($row['date'] === null || is_string($row['date'])) && is_int($row['at'] ?? null) && $now - $row['at'] < self::CACHE_TTL) {
                $fresh[$key] = ['date' => $row['date'], 'at' => $row['at']];
            }
        }

        return $fresh;
    }
}
