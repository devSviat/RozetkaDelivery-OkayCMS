<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\CredentialsProvider;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\StatusDictionary;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionRows;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionService;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\Mutex;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;
use Psr\Log\LoggerInterface;

/**
 * Статуси ЕН. Вебхуків Rozetka не має: тік пакетами питає `track/status` для незавершених ЕН. `has_paid`,
 * `payment_fee` і дати зберігання є лише в `track/{id}`, тож він іде поштучно, з бюджетом на тік: на переходах і для
 * виданих ЕН з післяплатою, доки Rozetka не підтвердить оплату.
 * Статусів замовлень модуль не змінює.
 */
final class ParcelTracking
{
    /** ЕН, що все ще в русі через два місяці, застрягла; повторні запити лише гальмують чергу. */
    public const int TRACK_FOR_DAYS = 60;
    /** Скільки номерів у одному `track/status`: межу API не названо, стартове значення. */
    public const int BATCH = 20;
    public const int EXTRA_GETS = 10;
    /**
     * Видану ЕН з післяплатою без `has_paid = 1` тік перечитує з того, що лишилось від EXTRA_GETS, не частіше
     * PAID_RECHECK_MINUTES і не довше PAID_RECHECK_DAYS від видачі: коли Rozetka ставить `has_paid`, не
     * задокументовано, а без нього чек «при отриманні» не виставляється.
     */
    public const int PAID_RECHECK_DAYS = 14;
    public const int PAID_RECHECK_MINUTES = 60;
    /** 25 пакетних запитів на тік: при 5 запитах/с це кілька секунд, а не весь тайм-аут задачі. */
    public const int PER_TICK = 500;
    /** Тайм-аут — це Unreachable, і він зупиняє тік: завислий API забирає 30 с, а не весь тік у 600 с. */
    private const int TIMEOUT_SECONDS = 30;
    /** Реєстр, не закритий за два тижні, уже ніхто не прийме; його оновлює кнопка на сторінці реєстрів. */
    public const int RECEPTIONS_FOR_DAYS = 14;
    public const int RECEPTIONS_PER_TICK = 5;
    private const string MUTEX = 'track';
    private const string UNKNOWN = 'unknown_statuses';
    private const int UNKNOWN_KEPT = 50;

    private readonly RozetkaClient $client;

    /** @param \Closure(): int $now */
    public function __construct(
        RozetkaClient $client,
        private readonly ParcelRows $rows,
        private readonly StatusDictionary $dictionary,
        private readonly Mutex $mutex,
        private readonly AuthFlags $auth,
        private readonly CredentialsProvider $credentials,
        private readonly StateStorage $state,
        private readonly ParcelIssues $issues,
        private readonly LoggerInterface $logger,
        private readonly \Closure $now,
        private readonly ReceptionRows $receptions,
        private readonly ReceptionService $receptionService,
    ) {
        $this->client = $client->quick(self::TIMEOUT_SECONDS);
    }

    /**
     * Планувальник. Відмова авторизації, недоступний API чи вичерпаний бюджет зупиняють тік: решта запитів
     * упала б так само.
     *
     * @return array{polled: int, updated: int, extra: int}
     */
    public function tick(): array
    {
        $summary = ['polled' => 0, 'updated' => 0, 'extra' => 0];
        if (!$this->mutex->acquire(self::MUTEX)) {
            return $summary;
        }
        try {
            if (!$this->credentials->get()->configured()) {
                return $summary;
            }
            $since = date('Y-m-d H:i:s', ($this->now)() - self::TRACK_FOR_DAYS * 86400);
            $extraLeft = self::EXTRA_GETS;
            foreach (array_chunk($this->rows->unfinished($since, self::PER_TICK), self::BATCH) as $batch) {
                $this->pollBatch($batch, $extraLeft, $summary);
            }
            $this->recheckPaid($extraLeft, $summary);
            $receptionsSince = date('Y-m-d H:i:s', ($this->now)() - self::RECEPTIONS_FOR_DAYS * 86400);
            foreach ($this->receptions->open($receptionsSince, self::RECEPTIONS_PER_TICK) as $reception) {
                $this->receptionService->refresh((int)$reception->reception_id);
            }
        } catch (RozetkaApiException $e) {
            // Клієнт уже записав запит і вид помилки в лог.
            $this->auth->record(AuthFlags::TRACKING, $e);
        } finally {
            $this->mutex->release(self::MUTEX);
        }

        return $summary;
    }

    /** Кнопка «Оновити статус»: одна ЕН зараз, історія замінюється повною відповіддю. */
    public function refresh(int $orderId): ParcelOutcome
    {
        $row = $this->rows->forOrder($orderId);
        $trackId = ParcelService::track($row->track_id ?? null);
        if ($row === null || PendingParcel::is($row) || $trackId === null) {
            return ParcelOutcome::refused('sviat__rozetka__parcel_err_none');
        }
        try {
            $items = TrackingAnswer::parse($this->client->get('track/status', ['id' => [$trackId]])['data']);
            $item = array_values(array_filter($items, static fn(array $i): bool => $i['track_id'] === $trackId))[0] ?? null;
            $values = $item === null ? [] : $this->values($row, $item, true);
            $values += $this->details($trackId);
        } catch (RozetkaApiException $e) {
            $this->auth->record(AuthFlags::TRACKING, $e);

            return ParcelOutcome::failed($this->issues->explain($e));
        }
        $this->auth->clear(AuthFlags::TRACKING);
        $this->rows->saveEvent($orderId, $values + ['checked_at' => $this->stamp()]);

        return new ParcelOutcome(true, $trackId);
    }

    /**
     * @param list<object> $batch
     * @param array{polled: int, updated: int, extra: int} $summary
     * @throws RozetkaApiException
     */
    private function pollBatch(array $batch, int &$extraLeft, array &$summary): void
    {
        $byTrack = [];
        foreach ($batch as $row) {
            $byTrack[(string)$row->track_id] = $row;
        }
        try {
            $items = TrackingAnswer::parse($this->client->get('track/status', ['id' => array_map(strval(...), array_keys($byTrack))])['data']);
        } catch (RozetkaApiException $e) {
            if (!self::onlyThisRequest($e)) {
                throw $e;
            }

            return;
        }
        $this->auth->clear(AuthFlags::TRACKING);
        $summary['polled'] += count($byTrack);
        $stamp = $this->stamp();
        foreach ($items as $item) {
            $row = $byTrack[$item['track_id']] ?? null;
            if ($row === null) {
                continue;
            }
            unset($byTrack[$item['track_id']]);
            $values = $this->values($row, $item, false);
            if (self::needsDetails($row, $values)) {
                // Без даних оплати чи зберігання перехід не записується: наступний тік побачить його знову.
                if ($extraLeft <= 0) {
                    continue;
                }
                $extraLeft--;
                $summary['extra']++;
                try {
                    $values += $this->details($item['track_id']);
                } catch (RozetkaApiException $e) {
                    if (!self::onlyThisRequest($e)) {
                        throw $e;
                    }
                    continue;
                }
            }
            $this->rows->saveEvent((int)$row->order_id, $values + ['checked_at' => $stamp]);
            $summary['updated']++;
        }
        // ЕН без відповіді теж позначаються перевіреними, щоб черга не стояла на них.
        foreach ($byTrack as $row) {
            $this->rows->saveEvent((int)$row->order_id, ['checked_at' => $stamp]);
        }
    }

    /**
     * @param array{polled: int, updated: int, extra: int} $summary
     * @throws RozetkaApiException
     */
    private function recheckPaid(int &$extraLeft, array &$summary): void
    {
        if ($extraLeft <= 0) {
            return;
        }
        $now = ($this->now)();
        $rows = $this->rows->unpaidCod(
            date('Y-m-d H:i:s', $now - self::PAID_RECHECK_DAYS * 86400),
            date('Y-m-d H:i:s', $now - self::PAID_RECHECK_MINUTES * 60),
            $extraLeft,
        );
        foreach ($rows as $row) {
            $extraLeft--;
            $summary['extra']++;
            $values = [];
            try {
                $values = $this->details((string)$row->track_id);
                $this->auth->clear(AuthFlags::TRACKING);
            } catch (RozetkaApiException $e) {
                if (!self::onlyThisRequest($e)) {
                    throw $e;
                }
            }
            $this->rows->saveEvent((int)$row->order_id, $values + ['checked_at' => $this->stamp()]);
        }
    }

    /**
     * @param array{track_id: string, last: ?TrackingEvent, statuses: list<TrackingEvent>} $item
     * @return array<string, mixed>
     */
    private function values(object $row, array $item, bool $replaceHistory): array
    {
        $history = $replaceHistory ? [] : ParcelHistory::decode($row->history ?? null);
        foreach ([...$item['statuses'], ...($item['last'] !== null ? [$item['last']] : [])] as $event) {
            $history = ParcelHistory::append($history, $event);
        }
        $values = ['history' => ParcelHistory::encode($history)];
        $last = $item['last'];
        if ($last === null) {
            return $values;
        }
        $current = is_string($row->status_group ?? null) && $row->status_group !== '' ? $row->status_group : null;
        $group = ParcelStatus::group($last->status(), $this->dictionary);
        if ($group === null) {
            $this->rememberUnknown($last->raw);
        }
        $values += [
            'status_code' => $last->code,
            'status_raw' => $last->raw,
            'status_group' => $group ?? $current,
            'partial' => ParcelStatus::partial($last->status()) ? 1 : 0,
            'event_name' => mb_substr($last->name !== '' ? $last->name : (string)($last->code !== null ? $this->dictionary->name($last->code) : ''), 0, TrackingEvent::TEXT_LENGTH),
            'event_at' => $last->at,
        ];
        if (empty($row->arrived_at)) {
            foreach ($history as $entry) {
                if (ParcelStatus::group($entry['code'] ?? $entry['raw'], $this->dictionary) === 'arrived') {
                    $values['arrived_at'] = $entry['at'];
                    break;
                }
            }
        }

        return $values;
    }

    /**
     * Прибуття дає дати зберігання; видача ЕН з післяплатою — `has_paid` і фактичну комісію. Без післяплати
     * видача нового в `track/{id}` не має.
     *
     * @param array<string, mixed> $values
     */
    private static function needsDetails(object $row, array $values): bool
    {
        $before = $row->status_group ?? null;
        $after = $values['status_group'] ?? $before;
        if ($after === $before) {
            return false;
        }

        return $after === 'arrived' || ($after === 'received' && (float)($row->cod_amount ?? 0) > 0);
    }

    /**
     * @return array<string, mixed>
     * @throws RozetkaApiException
     */
    private function details(string $trackId): array
    {
        $track = $this->client->get('track/' . rawurlencode($trackId))['data'];

        return array_intersect_key(
            ParcelService::fromTrack(is_array($track) ? $track : []),
            array_flip(['expiration_date', 'delivery_date', 'has_paid', 'payment_fee']),
        );
    }

    private function rememberUnknown(string $raw): void
    {
        $known = json_decode((string)$this->state->get(self::UNKNOWN), true);
        $known = is_array($known) ? array_values(array_filter($known, is_string(...))) : [];
        if (in_array($raw, $known, true)) {
            return;
        }
        $known[] = $raw;
        $this->state->set(self::UNKNOWN, json_encode(array_slice($known, -self::UNKNOWN_KEPT), JSON_UNESCAPED_UNICODE));
        $this->logger->notice('Rozetka tracking: unknown status ' . mb_substr($raw, 0, 64));
    }

    /** Відмова саме на ці номери (чи зіпсована відповідь): решта тіку не залежить від неї. */
    private static function onlyThisRequest(RozetkaApiException $e): bool
    {
        return in_array($e->kind, [ApiErrorKind::Rejected, ApiErrorKind::NotFound, ApiErrorKind::Malformed, ApiErrorKind::Empty], true);
    }

    private function stamp(): string
    {
        return date('Y-m-d H:i:s', ($this->now)());
    }
}
