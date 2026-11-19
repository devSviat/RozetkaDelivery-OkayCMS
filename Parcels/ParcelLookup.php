<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Psr\Log\LoggerInterface;

/**
 * Шукає в Rozetka ЕН, створення якої обірвалося: `GET track` за `visible_id` спроби в київський день резерву й
 * наступний. Збіг — лише точний `visible_id`: фільтр API може бути частковим, як у `id`.
 * Нуль збігів рядок не звільняє: «не створено» вирішує менеджер (ParcelService::unlink).
 * У лог іде лише кількість знайденого, без даних одержувача.
 */
final class ParcelLookup
{
    private const string P = 'sviat__rozetka__';
    /** Спроба одна на резерв, тож справжніх збігів — один-два; більше означає чужі дублі `visible_id`. */
    private const int LIMIT = 20;

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly RozetkaClient $client,
        private readonly ParcelRows $rows,
        private readonly ParcelIssues $issues,
        private readonly LoggerInterface $logger,
        private readonly \Closure $now,
    ) {
    }

    public function find(int $orderId): ParcelOutcome
    {
        return $this->resolve($orderId, null);
    }

    /** Перечитує Rozetka й прив'язує лише того кандидата, що й тепер проходить усі фільтри. */
    public function choose(int $orderId, string $trackId): ParcelOutcome
    {
        return $this->resolve($orderId, TrackNumber::normalize($trackId) ?? '');
    }

    private function resolve(int $orderId, ?string $chosen): ParcelOutcome
    {
        $row = $this->rows->forOrder($orderId);
        if ($row === null) {
            return ParcelOutcome::refused(self::P . 'parcel_err_none');
        }
        if (!PendingParcel::is($row)) {
            return ParcelOutcome::refused(self::P . 'parcel_exists', is_string($row->track_id ?? null) ? $row->track_id : null);
        }
        if (!PendingParcel::needsCheck($row, ($this->now)())) {
            return ParcelOutcome::refused(self::P . 'parcel_err_pending');
        }
        $visibleId = is_string($row->visible_id ?? null) ? $row->visible_id : '';
        if ($visibleId === '') {
            return ParcelOutcome::refused(self::P . 'parcel_err_lookup_none');
        }

        try {
            $found = $this->matches($orderId, $row, $visibleId);
        } catch (RozetkaApiException $e) {
            return ParcelOutcome::failed($this->issues->explain($e));
        }
        if ($chosen !== null) {
            $found = array_values(array_filter($found, static fn(array $item): bool => $item['track_id'] === $chosen));
            if ($found === []) {
                return ParcelOutcome::refused(self::P . 'parcel_err_lookup_gone');
            }
        }
        $this->logger->info("Rozetka lookup for order {$orderId}: " . count($found) . ' match(es)');

        return match (count($found)) {
            0 => ParcelOutcome::refused(self::P . 'parcel_err_lookup_none'),
            1 => $this->bind($orderId, $found[0]),
            // Яка з ЕН справжня, знає менеджер: автоматичний вибір міг би прив'язати дубль.
            default => new ParcelOutcome(false, null, [new ParcelIssue(null, self::P . 'parcel_lookup_many')], array_map(
                static fn(array $item): array => [
                    'track_id' => $item['track_id'],
                    'date' => KyivTime::toLocal($item['raw']['created_at'] ?? null),
                    'receiver' => ParcelDetails::fromApi($item['raw'])['recipient_name'] ?? null,
                    'cost' => is_numeric($item['raw']['cost'] ?? null) ? round((float)$item['raw']['cost'], 2) : null,
                ],
                $found,
            )),
        };
    }

    /** @param array{track_id: string, raw: array<mixed>} $item */
    private function bind(int $orderId, array $item): ParcelOutcome
    {
        $saved = $this->rows->complete($orderId, ParcelService::fromTrack($item['raw']) + ['track_id' => $item['track_id']]);
        if (!$saved) {
            return ParcelOutcome::refused(self::P . 'parcel_err_not_saved', $item['track_id']);
        }
        $this->logger->info("Rozetka parcel for order {$orderId} found by lookup");

        return new ParcelOutcome(true, $item['track_id']);
    }

    /**
     * @return list<array{track_id: string, raw: array<mixed>}>
     * @throws RozetkaApiException
     */
    private function matches(int $orderId, object $row, string $visibleId): array
    {
        $reserved = strtotime((string)($row->reserved_at ?? $row->created_at ?? ''));
        $start = $reserved === false ? ($this->now)() : $reserved;
        $answer = $this->client->get('track', [
            'visible_id' => $visibleId,
            'created_date_start' => KyivTime::day($start),
            'created_date_end' => KyivTime::day($start + 86400),
            'is_deleted' => false,
            'limit' => self::LIMIT,
        ]);
        $found = [];
        foreach (is_array($answer['data']) && array_is_list($answer['data']) ? $answer['data'] : [] as $item) {
            if (!is_array($item) || ($item['visible_id'] ?? null) !== $visibleId || ($item['is_deleted'] ?? false) === true) {
                continue;
            }
            $trackId = ParcelService::track($item['id'] ?? null);
            if ($trackId === null || isset($found[$trackId])) {
                continue;
            }
            $owner = $this->rows->orderOfTrack($trackId);
            if ($owner !== null && $owner !== $orderId) {
                continue;
            }
            $found[$trackId] = ['track_id' => $trackId, 'raw' => $item];
        }

        return array_values($found);
    }
}
