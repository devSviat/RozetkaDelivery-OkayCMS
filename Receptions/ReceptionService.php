<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Receptions;

use Okay\Core\Settings;
use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelService;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PendingParcel;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use Psr\Log\LoggerInterface;

/**
 * Реєстр про прийом: з його отримання точкою, за тарифами (п. 2.1.3), починається відповідальність Rozetka.
 * Магазин формує його з ЕН, які ще не прийняті й не в реєстрі; точка відправки — точка прийому з налаштувань.
 */
final class ReceptionService
{
    public const int LIMIT = 100;
    private const string P = 'sviat__rozetka__';

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly RozetkaClient $client,
        private readonly ParcelRows $parcels,
        private readonly ReceptionRows $receptions,
        private readonly Settings $settings,
        private readonly ParcelIssues $issues,
        private readonly LoggerInterface $logger,
        private readonly \Closure $now,
    ) {
    }

    /**
     * @param list<int> $orderIds
     * @return array{reception_id: ?int, rejected: list<array{track_id: string, reason: string}>, skipped: list<array{order_id: int, key: string}>, error: ?string}
     */
    public function create(array $orderIds): array
    {
        $result = ['reception_id' => null, 'rejected' => [], 'skipped' => [], 'error' => null];
        $orderIds = array_slice(array_values(array_unique(array_filter($orderIds, static fn(int $id): bool => $id > 0))), 0, self::LIMIT);
        $rows = $this->parcels->forOrders($orderIds);
        $tracks = [];
        foreach ($orderIds as $orderId) {
            $row = $rows[$orderId] ?? null;
            $trackId = ParcelService::track($row->track_id ?? null);
            $key = match (true) {
                $row === null || PendingParcel::is($row) || $trackId === null => 'reception_skip_no_parcel',
                !in_array($row->status_group ?? null, [null, '', 'created'], true) => 'reception_skip_not_created',
                !empty($row->reception_id) => 'reception_skip_in_reception',
                default => null,
            };
            if ($key !== null) {
                $result['skipped'][] = ['order_id' => $orderId, 'key' => self::P . $key];
                continue;
            }
            $tracks[] = (string)$trackId;
        }
        if ($tracks === []) {
            return ['error' => self::P . 'reception_err_none'] + $result;
        }
        $department = ShippingConfig::fromSettings($this->settings->get(...))->senderPointId;
        if ($department === '') {
            return ['error' => self::P . 'reception_err_sender'] + $result;
        }

        try {
            $data = $this->client->post('reception/create', ['data' => ['track_nums' => $tracks, 'department' => $department]])['data'];
        } catch (RozetkaApiException $e) {
            return ['error' => $this->issues->explain($e)[0]->text] + $result;
        }
        $receptionId = is_array($data) && is_numeric($data['reception_id'] ?? null) ? (int)$data['reception_id'] : 0;
        if ($receptionId <= 0) {
            $this->logger->warning('Rozetka reception: answer without reception_id');

            return ['error' => self::P . 'parcel_api_malformed'] + $result;
        }
        $rejected = self::rejected($data['rejected_tracks'] ?? null);
        $accepted = array_values(array_diff($tracks, array_column($rejected, 'track_id')));
        $this->parcels->setReception($accepted, $receptionId);
        $stamp = date('Y-m-d H:i:s', ($this->now)());
        $this->receptions->save($receptionId, [
            'status' => 'new',
            'department_id' => $department,
            'tracks_amount' => count($accepted),
            'created_at' => $stamp,
            'checked_at' => $stamp,
        ]);
        $this->logger->info("Rozetka reception {$receptionId} created: " . count($accepted) . ' accepted, ' . count($rejected) . ' rejected');

        return ['reception_id' => $receptionId, 'rejected' => $rejected] + $result;
    }

    /** «Сформувати з усіх готових»: ті самі правила, що й у масової дії. */
    public function createFromReady(): array
    {
        return $this->create(array_map(static fn(object $row): int => (int)$row->order_id, $this->parcels->readyForReception(self::LIMIT)));
    }

    /** @return array{ok: bool, error: ?string} */
    public function refresh(int $receptionId): array
    {
        try {
            $data = $this->client->get('reception/' . $receptionId)['data'];
        } catch (RozetkaApiException $e) {
            return ['ok' => false, 'error' => $this->issues->explain($e)[0]->text];
        }
        $data = is_array($data) ? $data : [];
        $values = ['checked_at' => date('Y-m-d H:i:s', ($this->now)())];
        $status = ReceptionStatus::of($data['status'] ?? null);
        if ($status !== null) {
            $values['status'] = $status;
        }
        if (is_array($data['tracks'] ?? null)) {
            $values['tracks_amount'] = count($data['tracks']);
        } elseif (is_numeric($data['tracks_amount'] ?? null)) {
            $values['tracks_amount'] = (int)$data['tracks_amount'];
        }
        $this->receptions->save($receptionId, $values);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Видаляє лише реєстр, ще не переданий у точку: статус — зі свіжого `GET reception/{id}`, бо точка могла
     * почати прийом після останнього оновлення.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function delete(int $receptionId): array
    {
        try {
            $data = $this->client->get('reception/' . $receptionId)['data'];
            $status = ReceptionStatus::of(is_array($data) ? ($data['status'] ?? null) : null);
            if (!ReceptionStatus::isOpen($status)) {
                if ($status !== null) {
                    $this->receptions->save($receptionId, ['status' => $status, 'checked_at' => date('Y-m-d H:i:s', ($this->now)())]);
                }

                return ['ok' => false, 'error' => self::P . 'reception_err_not_deletable'];
            }
            $this->client->delete('reception/' . $receptionId);
        } catch (RozetkaApiException $e) {
            // Реєстру в Rozetka вже немає (видалили в кабінеті): локальний рядок інакше застряг би назавжди.
            if ($e->kind !== ApiErrorKind::NotFound) {
                return ['ok' => false, 'error' => $this->issues->explain($e)[0]->text];
            }
        }
        $this->parcels->clearReception($receptionId);
        $this->receptions->remove($receptionId);
        $this->logger->info("Rozetka reception {$receptionId} deleted");

        return ['ok' => true, 'error' => null];
    }

    /** @return list<array{track_id: string, reason: string}> причина — текст Rozetka як є; шаблон її екранує */
    private static function rejected(mixed $raw): array
    {
        $rejected = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            $trackId = is_array($item) ? ParcelService::track($item['track_id'] ?? null) : null;
            if ($trackId !== null) {
                $reason = is_string($item['reason'] ?? null) ? mb_substr(trim($item['reason']), 0, 255) : '';
                $rejected[] = ['track_id' => $trackId, 'reason' => $reason];
            }
        }

        return $rejected;
    }
}
