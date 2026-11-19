<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionStatus;

/**
 * Картка ЕН на сторінці замовлення: значення рядка, відформатовані для шаблону; слова — мовні ключі.
 * Чого немає в рядку, того немає і в картці (null чи порожній список): шаблон таке поле не друкує.
 */
final class ParcelCard
{
    private const array PAYERS = ['sender', 'receiver', 'other'];
    private const array REASONS = ['setting', 'in_total', 'free', 'manual'];

    /** ЕН без реєстру, створена понад добу тому: її, найімовірніше, вже везуть у точку. */
    private const int RECEPTION_HINT_AFTER = 86400;

    /**
     * @param string $fiscal CodConfig::FISCAL_*: хто фіскалізує післяплату
     * @param ?object $reception рядок ReceptionEntity реєстру цієї ЕН
     * @param int $now 0 — без підказки про реєстр
     * @return array<string, mixed>
     */
    public static function fromRow(object $row, string $fiscal, ?object $reception = null, int $now = 0): array
    {
        $group = is_string($row->status_group ?? null) && in_array($row->status_group, ParcelStatus::GROUPS, true) ? $row->status_group : null;
        $details = ParcelDetails::decode($row->details ?? null);
        $final = ParcelStatus::isFinal($group);
        $trackId = ParcelService::track($row->track_id ?? null);
        $payer = $row->delivery_payer ?? null;
        $reason = $row->payer_reason ?? null;
        $cod = self::money($row->cod_amount ?? null);
        $hasPaid = $row->has_paid ?? null;

        return [
            'trackId' => $trackId,
            // Чи приймає `rozetka.delivery/tracking/parcel` номер в URL, не відомо (М16): без підтвердження посилання немає.
            'trackUrl' => null,
            'statusGroup' => $group,
            'statusKey' => $group !== null ? 'sviat__rozetka__parcel_group_' . $group : null,
            'partial' => (int)($row->partial ?? 0) === 1,
            'event' => self::event($row),
            'recipient' => self::text($details['recipient_name'] ?? null),
            'point' => self::text($details['point_title'] ?? null),
            'shippingCost' => self::money($row->shipping_cost ?? null),
            'payerKey' => in_array($payer, self::PAYERS, true) ? 'sviat__rozetka__parcel_pays_' . $payer : null,
            'payerReasonKey' => in_array($reason, self::REASONS, true) ? 'sviat__rozetka__parcel_reason_' . $reason : null,
            'cod' => $cod === null ? null : [
                'amount' => $cod,
                'fee' => self::money($row->payment_fee ?? null),
                'paid' => $hasPaid === null || $hasPaid === '' ? null : (int)$hasPaid === 1,
            ],
            // Хто видає чек за післяплату, не вирішено: автоматичного чека немає, і менеджер має це знати.
            'fiscalBanner' => $cod !== null && $fiscal === CodConfig::FISCAL_UNKNOWN,
            'declared' => self::money($row->declared_value ?? null),
            // Після видачі, повернення чи скасування планова дата й кінець зберігання вже нічого не кажуть.
            'deliveryDate' => $final ? null : self::date($row->delivery_date ?? null),
            'expirationDate' => $final ? null : self::dateTime($row->expiration_date ?? null),
            'more' => self::more($row, $details),
            'history' => array_map(
                static fn(array $e): array => ['name' => $e['name'] !== '' ? $e['name'] : $e['raw'], 'at' => (string)self::dateTime($e['at'])],
                array_reverse(ParcelHistory::decode($row->history ?? null)),
            ),
            'created' => self::dateTime($row->created_at ?? null),
            'checked' => self::dateTime($row->checked_at ?? null),
            'reception' => self::reception($row, $reception),
            'receptionHint' => self::receptionHint($row, $group, $now),
        ];
    }

    /** @return ?array{number: string, statusKey: ?string} */
    private static function reception(object $row, ?object $reception): ?array
    {
        $id = (int)($row->reception_id ?? 0);
        if ($id <= 0) {
            return null;
        }
        $status = $reception !== null && (int)($reception->reception_id ?? 0) === $id ? ReceptionStatus::of($reception->status ?? null) : null;

        return ['number' => (string)$id, 'statusKey' => $status !== null ? 'sviat__rozetka__reception_status_' . $status : null];
    }

    private static function receptionHint(object $row, ?string $group, int $now): bool
    {
        $created = strtotime((string)($row->created_at ?? ''));

        return $now > 0 && $group === 'created' && (int)($row->reception_id ?? 0) <= 0
            && $created !== false && $now - $created > self::RECEPTION_HINT_AFTER;
    }

    /** @return ?array{name: string, at: ?string} */
    private static function event(object $row): ?array
    {
        $name = self::text($row->event_name ?? null) ?? self::text($row->status_raw ?? null);

        return $name === null ? null : ['name' => $name, 'at' => self::dateTime($row->event_at ?? null)];
    }

    /**
     * @param array<string, string|int|float> $details
     * @return array<string, string>
     */
    private static function more(object $row, array $details): array
    {
        $sides = array_filter([$details['length'] ?? null, $details['width'] ?? null, $details['height'] ?? null], is_int(...));

        return array_filter([
            'weight' => isset($details['weight_kg']) ? self::number((float)$details['weight_kg']) : null,
            'sides' => count($sides) === 3 ? implode('×', $sides) : null,
            'volume' => isset($details['volume']) ? self::number((float)$details['volume']) : null,
            'description' => self::text($details['description'] ?? null),
            'visibleId' => self::text($row->visible_id ?? null),
        ], static fn(?string $value): bool => $value !== null);
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    /** Без зайвих нулів: 1.25 кг, а не 1.250. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    private static function money(mixed $value): ?string
    {
        return is_numeric($value) && (float)$value > 0 ? number_format((float)$value, 2, '.', '') : null;
    }

    private static function date(mixed $value): ?string
    {
        $date = is_string($value) && $value !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10)) : false;

        return $date ? $date->format('d.m.Y') : null;
    }

    private static function dateTime(mixed $value): ?string
    {
        $date = is_string($value) && $value !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value) : false;

        return $date ? $date->format('d.m.Y H:i') : null;
    }
}
