<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries;

use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RequestBudget;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\CityRow;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointKind;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointRow;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Store\DictionaryStore;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\Mutex;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;

/**
 * Одне завдання планувальника виконує всю роботу з довідниками, кнопку в адмінці включно: кнопка лише
 * залишає запит, тож два запуски ніколи не перетинаються. Обидва методи публічні, токен не потрібен.
 */
final class DictionaryRunner
{
    public const int PAGE_SIZE = 100;
    /** Тайм-аут завдання планувальника. */
    public const int TASK_TIMEOUT = 600;
    /** Дельт у Rozetka немає: кожен запуск — повний обхід, тож раз на добу. */
    private const int EVERY = 86400;
    private const int RETRY = 3600;
    /** Захист від нескінченного обходу, якщо pagination.page_count зіпсований: ~1000 точок — це 11 сторінок. */
    private const int MAX_PAGES = 100;
    /** Обхід робить більше запитів, ніж бюджет дає за секунду: на відмові бюджету чекаємо наступного вікна. */
    private const int RATE_RETRIES = 5;
    private const int MISMATCH_NAMES = 5;
    private const string LOCK = 'dict';

    /**
     * @param \Closure(): int $now
     * @param ?\Closure(): void $wait пауза до наступного вікна бюджету; типово — секунда сну
     */
    public function __construct(
        private readonly RozetkaClient $client,
        private readonly DictionaryStore $store,
        private readonly StatusDictionary $statuses,
        private readonly StateStorage $state,
        private readonly Mutex $mutex,
        private readonly \Closure $now,
        private readonly ?\Closure $wait = null,
    ) {
    }

    /** Кнопка «Оновити зараз»: обслуговує найближчий тік планувальника. */
    public function requestRun(): void
    {
        DictionaryStatus::request($this->state, ($this->now)());
    }

    /** Викликається планувальником кожні 5 хвилин; працює, лише коли пора, або з $force. */
    public function tick(bool $force = false): DictionaryStatus
    {
        // Перевірка runningSince і його запис — два кроки: два процеси, що стартували разом, пройшли б обидва.
        if (!$this->mutex->acquire(self::LOCK)) {
            return DictionaryStatus::load($this->state);
        }
        try {
            return $this->tickLocked($force);
        } finally {
            $this->mutex->release(self::LOCK);
        }
    }

    private function tickLocked(bool $force): DictionaryStatus
    {
        $now = ($this->now)();
        $status = DictionaryStatus::load($this->state);
        if ($status->runningSince !== null) {
            // Блокування тримається весь запуск, тож runningSince під ним лишив процес, убитий до `finally`.
            $status->lastRun = ['started' => $status->runningSince, 'finished' => $status->runningSince, 'status' => DictionaryStatus::FAILED, 'error' => 'interrupted'];
            $status->runningSince = null;
            $status->save($this->state);
        }
        if (!$force && !$this->isDue($status, $now)) {
            return $status;
        }
        $status->serveRequest();
        $status->runningSince = $now;
        $status->save($this->state);

        $run = ['started' => $now, 'finished' => $now, 'status' => DictionaryStatus::FAILED, 'error' => null];
        try {
            $run = $this->sweep($now) + $run;
            $run['statuses'] = $this->loadStatuses();
            if ($run['statuses'] !== 'ok' && $run['status'] === DictionaryStatus::OK) {
                $run['status'] = DictionaryStatus::PARTIAL;
            }
        } catch (RozetkaApiException $e) {
            $run['error'] = $e->kind->value;
        } catch (\Throwable $e) {
            $run['error'] = RozetkaApiException::mask($e->getMessage());
        } finally {
            $run['finished'] = ($this->now)();
            $status->lastRun = $run;
            $status->runningSince = null;
            $status->save($this->state);
        }

        return $status;
    }

    private function isDue(DictionaryStatus $status, int $now): bool
    {
        if ($status->requestedAt !== null || $status->lastRun === null) {
            return true;
        }
        $wait = ($status->lastRun['status'] ?? null) === DictionaryStatus::OK ? self::EVERY : self::RETRY;

        return $now - (int)($status->lastRun['finished'] ?? 0) >= $wait;
    }

    /** @return array<string, mixed> status, counts, received, total, reason */
    private function sweep(int $run): array
    {
        $raw = 0;
        $total = null;
        $points = [];
        $cities = [];
        $page = 1;
        do {
            $answer = $this->paced(fn(): array => $this->client->get('department', ['page' => $page, 'limit' => self::PAGE_SIZE], auth: false));
            $items = is_array($answer['data']) && array_is_list($answer['data']) ? $answer['data'] : [];
            $raw += count($items);
            $total = is_int($answer['pagination']['total_count'] ?? null) ? $answer['pagination']['total_count'] : $total;
            $pages = is_int($answer['pagination']['page_count'] ?? null) ? $answer['pagination']['page_count'] : 1;
            foreach ($items as $item) {
                $point = is_array($item) ? PointRow::fromApi($item, $run) : null;
                if ($point === null) {
                    continue;
                }
                $points[$point->id] = $point;
                $city = CityRow::fromPoint($item['location']);
                if ($city !== null) {
                    $cities[$city->id] = $city;
                }
            }
            $page++;
        } while ($page <= min($pages, self::MAX_PAGES));

        $pointLoader = new TableLoader($this->store, DictionaryStore::POINTS, $run);
        $stored = $pointLoader->stored();
        $parsed = count($points);
        $result = ['received' => $raw, 'total' => $total];
        // Рахуються розібрані точки, а не сирі записи: зміна формату чи дублі між сторінками інакше пройшли б
        // перевірки, а deleteNotSeen() стер би все, що не розібрано.
        $reason = match (true) {
            $raw === 0 => 'empty',
            $total !== $raw => 'truncated',
            $parsed !== $raw => 'unparsed',
            $stored > 0 && $parsed * 2 < $stored => 'halved',
            default => null,
        };
        if ($reason !== null) {
            // Урізана відповідь 200 під навантаженням не повинна стерти довідник: збережене лишається цілим.
            return ['status' => DictionaryStatus::PARTIAL, 'reason' => $reason] + $result;
        }

        $cityLoader = new TableLoader($this->store, DictionaryStore::CITIES, $run);
        foreach ($cities as $city) {
            $cityLoader->put($city->columns());
        }
        foreach ($points as $point) {
            $pointLoader->put($point->columns());
        }
        $pointCounts = $pointLoader->finish();
        $cityCounts = $cityLoader->finish();
        $this->store->recountPoints();
        $this->remember($points);

        return ['status' => DictionaryStatus::OK, 'counts' => [
            DictionaryStore::POINTS => $pointCounts->toArray(),
            DictionaryStore::CITIES => $cityCounts->toArray(),
        ]] + $result;
    }

    /** Сигнали для «Стану довідників»: невідомі типи, розбіжність ознак «лише передоплата», підозрілі одиниці лімітів. */
    private function remember(array $points): void
    {
        $unknown = [];
        $mismatch = [];
        $suspect = 0;
        foreach ($points as $point) {
            if ($point->kind === PointKind::Other) {
                $unknown[$point->typeId] = ($unknown[$point->typeId] ?? 0) + 1;
            }
            if ($point->prepayMismatch()) {
                $mismatch[] = $point->title;
            }
            if ($point->limits->suspectUnits) {
                $suspect++;
            }
        }
        $this->state->set('unknown_types', json_encode($unknown, JSON_FORCE_OBJECT));
        $this->state->set('prepay_mismatch', json_encode(['count' => count($mismatch), 'names' => array_slice($mismatch, 0, self::MISMATCH_NAMES)], JSON_UNESCAPED_UNICODE));
        $this->state->set('limit_units_suspect', (string)$suspect);
    }

    /** @return string 'ok' або вид помилки; збій словника статусів не скасовує оновлених точок */
    private function loadStatuses(): string
    {
        try {
            $answer = $this->paced(fn(): array => $this->client->get('track-status', auth: false));
        } catch (RozetkaApiException $e) {
            return $e->kind->value;
        }

        return is_array($answer['data']) && $this->statuses->store($answer['data']) ? 'ok' : ApiErrorKind::Empty->value;
    }

    /**
     * @param \Closure(): array{data: mixed, pagination: ?array<mixed>} $call
     * @return array{data: mixed, pagination: ?array<mixed>}
     */
    private function paced(\Closure $call): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $call();
            } catch (RozetkaApiException $e) {
                if ($e->kind !== ApiErrorKind::RateLimited || $attempt >= self::RATE_RETRIES) {
                    throw $e;
                }
                ($this->wait ?? static fn() => sleep(RequestBudget::WINDOW))();
            }
        }
    }
}
