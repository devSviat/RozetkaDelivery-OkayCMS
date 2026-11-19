<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Psr\Log\LoggerInterface;

/**
 * Створює, прив'язує, видаляє й забуває ЕН замовлення. POST track надсилається рівно один раз на резерв:
 * після обриву Rozetka могла вже створити ЕН, а чи відсікає вона дублі `visible_id`, не відомо.
 * У лог іде лише id замовлення й вид помилки: ні тіла запиту, ні ПІБ чи телефону одержувача.
 */
final class ParcelService
{
    private const string P = 'sviat__rozetka__';

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly RozetkaClient $client,
        private readonly ParcelRows $rows,
        private readonly ParcelIssues $issues,
        private readonly LoggerInterface $logger,
        private readonly \Closure $now,
    ) {
    }

    public function create(int $orderId, ParcelDraft $draft): ParcelOutcome
    {
        $errors = $draft->errors();
        if ($errors !== []) {
            return ParcelOutcome::failed(array_map(static fn(array $e): ParcelIssue => new ParcelIssue($e['field'], $e['key']), $errors));
        }
        $occupied = $this->occupied($this->rows->forOrder($orderId));
        if ($occupied !== null) {
            return $occupied;
        }
        // UNIQUE order_id у reserve() лишається захистом від двох одночасних запитів, що обидва пройшли перевірку вище.
        $visibleId = $this->rows->reserve($orderId, date('Y-m-d H:i:s', ($this->now)()));
        if ($visibleId === null) {
            return $this->occupied($this->rows->forOrder($orderId)) ?? ParcelOutcome::refused(self::P . 'parcel_err_reserve');
        }

        try {
            $answer = $this->client->post('track', ['data' => $draft->body() + ['visible_id' => $visibleId]]);
        } catch (RozetkaApiException $e) {
            if (self::mayExist($e)) {
                $this->rows->markUncertain($orderId);
                $this->logger->warning("Rozetka parcel for order {$orderId}: result unknown, kind={$e->kind->value}");

                return ParcelOutcome::failed([new ParcelIssue(null, self::P . 'parcel_err_uncertain'), ...$this->issues->explain($e)]);
            }
            // Rozetka відмовила чи запит не пішов: ЕН немає, замовлення знову вільне.
            $this->rows->release($orderId);

            return ParcelOutcome::failed($this->issues->explain($e));
        } catch (\Throwable $e) {
            // Невідомо, чи запит устиг піти: замовлення чекає на пошук, а не на повтор.
            $this->rows->markUncertain($orderId);

            throw $e;
        }

        $data = is_array($answer['data']) ? $answer['data'] : [];
        $trackId = self::track($data['track_id'] ?? null);
        if ($trackId === null) {
            $this->rows->markUncertain($orderId);
            $this->logger->warning("Rozetka parcel for order {$orderId}: answer without track_id");

            return ParcelOutcome::refused(self::P . 'parcel_err_uncertain');
        }
        $saved = $this->rows->complete($orderId, [
            'track_id' => $trackId,
            'shipping_cost' => self::money($data['shipping_cost'] ?? null),
            'payment_fee' => self::money($data['payment_fee'] ?? null),
            'delivery_date' => KyivTime::toLocal($data['delivery_date'] ?? null),
            'cod_amount' => round($draft->codAmount, 2),
            'declared_value' => round($draft->declaredValue, 2),
            'delivery_payer' => $draft->payer,
            'payer_reason' => $draft->payerReason,
            'point_id' => strtolower((string)($draft->point->id ?? '')),
            'details' => ParcelDetails::encode(ParcelDetails::fromDraft($draft, $data)),
        ]);
        if (!$saved) {
            // ЕН у Rozetka є, а запис не вдався: звільнене замовлення дало б створити другу.
            $this->rows->markUncertain($orderId);
            $this->logger->warning("Rozetka parcel for order {$orderId}: created but not saved");

            return ParcelOutcome::refused(self::P . 'parcel_err_not_saved', $trackId);
        }
        $this->logger->info("Rozetka parcel for order {$orderId} created");

        return new ParcelOutcome(true, $trackId);
    }

    /**
     * Прив'язує ЕН, створену деінде, за номером. Перевірка — `GET track/{id}`: чужу ЕН API, найімовірніше,
     * не віддасть (404). Статус допише трекінг.
     */
    public function attach(int $orderId, string $input): ParcelOutcome
    {
        $trackId = TrackNumber::normalize($input);
        if ($trackId === null) {
            return ParcelOutcome::refused(self::P . 'parcel_err_track');
        }
        $row = $this->rows->forOrder($orderId);
        if ($row !== null && !PendingParcel::needsCheck($row, ($this->now)())) {
            return $this->occupied($row) ?? ParcelOutcome::refused(self::P . 'parcel_exists');
        }
        $owner = $this->rows->orderOfTrack($trackId);
        if ($owner !== null && $owner !== $orderId) {
            return ParcelOutcome::refused(self::P . 'parcel_err_track_elsewhere', (string)$owner);
        }

        try {
            $answer = $this->client->get('track/' . rawurlencode($trackId));
        } catch (RozetkaApiException $e) {
            return $e->kind === ApiErrorKind::NotFound
                ? ParcelOutcome::refused(self::P . 'parcel_err_not_ours')
                : ParcelOutcome::failed($this->issues->explain($e));
        }
        $track = is_array($answer['data']) ? $answer['data'] : [];
        if ($track === []) {
            return ParcelOutcome::refused(self::P . 'parcel_err_not_ours');
        }
        $now = date('Y-m-d H:i:s', ($this->now)());
        $saved = $this->rows->attach($orderId, self::fromTrack($track) + [
            'track_id' => $trackId,
            'status_group' => 'created',
            'created_at' => $now,
            'checked_at' => $now,
        ]);
        if (!$saved) {
            // Номер унікальний: тим часом інший запит міг прив'язати його до іншого замовлення.
            $owner = $this->rows->orderOfTrack($trackId);

            return $owner !== null && $owner !== $orderId
                ? ParcelOutcome::refused(self::P . 'parcel_err_track_elsewhere', (string)$owner)
                : ParcelOutcome::refused(self::P . 'parcel_err_pending');
        }
        $this->logger->info("Rozetka parcel for order {$orderId} attached by number");

        return new ParcelOutcome(true, $trackId);
    }

    /** Реєстр, з якого ще можна прибрати ЕН: сформований чи надрукований, але не переданий у точку. */
    private const array RECEPTION_OPEN = ['new', 'printed'];

    /**
     * Видаляє ЕН, яку Rozetka ще не прийняла. `DELETE track` не каже, що саме видалено, тож рядок зникає лише після
     * `GET track/{id}` з `is_deleted` чи 404; інакше лишається з помилкою, і менеджер бачить ЕН, яка, можливо, жива.
     */
    public function delete(int $orderId): ParcelOutcome
    {
        $row = $this->rows->forOrder($orderId);
        if ($row === null) {
            return ParcelOutcome::refused(self::P . 'parcel_err_none');
        }
        $trackId = self::track($row->track_id ?? null);
        if ($trackId === null || !self::deletable($row)) {
            return ParcelOutcome::refused(self::P . 'parcel_err_not_deletable');
        }
        $path = 'track/' . rawurlencode($trackId);

        try {
            $track = $this->client->get($path)['data'];
            $track = is_array($track) ? $track : [];
            if (($track['is_deleted'] ?? false) === true) {
                return $this->forget($orderId, $trackId);
            }
            if (($track['is_editable'] ?? false) !== true || !self::createdStatus($track['last_status'] ?? null)) {
                return ParcelOutcome::refused(self::P . 'parcel_err_not_deletable');
            }
            // Членство в реєстрі — зі свіжої ЕН: реєстр могли видалити чи змінити в кабінеті, і локальний номер застарів.
            if (($track['is_in_reception'] ?? false) === true) {
                $refused = $this->leaveReception($orderId, $trackId, (int)($row->reception_id ?? 0));
                if ($refused !== null) {
                    return $refused;
                }
            } elseif (!empty($row->reception_id)) {
                $this->rows->saveEvent($orderId, ['reception_id' => null]);
            }
            $this->client->delete('track', ['data' => [$trackId]]);
        } catch (RozetkaApiException $e) {
            return ParcelOutcome::failed($this->issues->explain($e));
        }

        try {
            $after = $this->client->get($path)['data'];
            $gone = is_array($after) && ($after['is_deleted'] ?? false) === true;
        } catch (RozetkaApiException $e) {
            $gone = $e->kind === ApiErrorKind::NotFound;
        }
        if (!$gone) {
            $this->logger->warning("Rozetka parcel for order {$orderId}: delete not confirmed");

            return ParcelOutcome::refused(self::P . 'parcel_err_delete_unconfirmed', $trackId);
        }

        return $this->forget($orderId, $trackId);
    }

    /** Кнопку «Видалити» показує лише ЕН у групі `created`; остаточно вирішує свіжий `GET track/{id}` у delete(). */
    public static function deletable(object $row): bool
    {
        return ($row->state ?? null) === PendingParcel::CREATED
            && self::track($row->track_id ?? null) !== null
            && in_array($row->status_group ?? null, [null, '', 'created'], true);
    }

    /**
     * Прибирає ЕН з реєстру перед видаленням; null — прибрано. Реєстр, сформований поза магазином, не відомий
     * (`reception_id` порожній), тож не чіпається.
     *
     * @throws RozetkaApiException
     */
    private function leaveReception(int $orderId, string $trackId, int $receptionId): ?ParcelOutcome
    {
        if ($receptionId <= 0) {
            return ParcelOutcome::refused(self::P . 'parcel_err_reception_foreign');
        }
        $reception = $this->client->get('reception/' . $receptionId)['data'];
        $status = is_array($reception) && is_array($reception['status'] ?? null) ? ($reception['status']['id'] ?? null) : null;
        if (!in_array($status, self::RECEPTION_OPEN, true)) {
            return ParcelOutcome::refused(self::P . 'parcel_err_reception_handed', (string)$receptionId);
        }
        $answer = $this->client->patch('reception/remove-tracks/' . $receptionId, ['track_nums' => [$trackId]])['data'];
        // Незрозуміла відповідь — не підтвердження: ЕН могла лишитися в реєстрі.
        if (!is_array($answer) || !is_array($answer['not_available_for_deletion_tracks'] ?? null)) {
            return ParcelOutcome::refused(self::P . 'parcel_err_reception_handed', (string)$receptionId);
        }
        $kept = $answer['not_available_for_deletion_tracks'];
        if (in_array($trackId, array_map(strval(...), array_filter($kept, is_scalar(...))), true)) {
            return ParcelOutcome::refused(self::P . 'parcel_err_reception_handed', (string)$receptionId);
        }
        $this->rows->saveEvent($orderId, ['reception_id' => null]);

        return null;
    }

    private function forget(int $orderId, string $trackId): ParcelOutcome
    {
        $this->rows->remove($orderId);
        $this->logger->info("Rozetka parcel for order {$orderId} deleted");

        return new ParcelOutcome(true, $trackId);
    }

    /** Лише група `created` (10010, 10020 чи їхні рядки схеми): прийняту Rozetka ЕН магазин не видаляє. */
    private static function createdStatus(mixed $status): bool
    {
        return (is_int($status) || is_string($status)) && ParcelStatus::group($status) === 'created';
    }

    /**
     * Забуває ЕН замовлення, не питаючи Rozetka: прийняту (її не видалити) або невідоме створення, якого
     * менеджер не знайшов. У Rozetka вона лишається.
     */
    public function unlink(int $orderId): ParcelOutcome
    {
        $row = $this->rows->forOrder($orderId);
        if ($row === null) {
            return ParcelOutcome::refused(self::P . 'parcel_err_none');
        }
        if (PendingParcel::running($row, ($this->now)())) {
            return ParcelOutcome::refused(self::P . 'parcel_err_pending');
        }
        $this->rows->remove($orderId);

        return new ParcelOutcome(true, is_string($row->track_id ?? null) ? $row->track_id : null);
    }

    /**
     * Поля рядка з ЕН `GET track(/{id})`: що показує картка без нових запитів.
     *
     * @param array<mixed> $track
     * @return array<string, mixed>
     */
    public static function fromTrack(array $track): array
    {
        $payer = $track['delivery_payer'] ?? null;
        $department = is_array($track['recipient']['department'] ?? null) ? $track['recipient']['department'] : [];
        $pointId = is_string($department['id'] ?? null) ? strtolower($department['id']) : null;

        return array_filter([
            'shipping_cost' => self::money($track['shipping_cost'] ?? null),
            'payment_fee' => self::money($track['payment_fee'] ?? null),
            'cod_amount' => self::money($track['cost'] ?? null),
            'declared_value' => self::money($track['insurance_cost'] ?? null),
            'has_paid' => is_bool($track['has_paid'] ?? null) ? (int)$track['has_paid'] : null,
            'delivery_payer' => in_array($payer, ['sender', 'receiver', 'other'], true) ? $payer : null,
            'point_id' => $pointId !== null && preg_match('/^[0-9a-f-]{36}$/', $pointId) === 1 ? $pointId : null,
            'expiration_date' => KyivTime::toLocal($track['expiration_date'] ?? null),
            'delivery_date' => KyivTime::toLocal($track['delivery_date'] ?? null),
            'details' => ParcelDetails::encode(ParcelDetails::fromApi($track)),
        ], static fn(mixed $value): bool => $value !== null);
    }

    /** Відмова, коли замовлення вже має рядок; null — вільне. */
    private function occupied(?object $row): ?ParcelOutcome
    {
        return match (true) {
            $row === null => null,
            PendingParcel::needsCheck($row, ($this->now)()) => ParcelOutcome::refused(self::P . 'parcel_err_uncertain'),
            PendingParcel::is($row) => ParcelOutcome::refused(self::P . 'parcel_err_pending'),
            default => ParcelOutcome::refused(self::P . 'parcel_exists', is_string($row->track_id ?? null) ? $row->track_id : null),
        };
    }

    /** Після цих запит міг дійти, і ЕН могла з'явитися: обрив, 5xx, 2xx з незрозумілим тілом. */
    private static function mayExist(RozetkaApiException $e): bool
    {
        return in_array($e->kind, [ApiErrorKind::Unreachable, ApiErrorKind::ServerError, ApiErrorKind::Malformed, ApiErrorKind::Empty], true);
    }

    /** Номер іде в шляхи друку, видалення й статусу: лише відома форма. */
    public static function track(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? TrackNumber::normalize((string)$value) : null;
    }

    private static function money(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? round((float)$value, 2) : null;
    }
}
