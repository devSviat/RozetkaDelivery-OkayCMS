<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;

/** Перетворює відмову на те, що показує форма ЕН: поле для позначки, текст і пораду. */
final class ParcelIssues
{
    private const string PREFIX = 'sviat__rozetka__';

    /**
     * `details[].property` Rozetka (без `data.` та індексів масивів) → поле форми. Назви — з тіла `POST track`
     * (ParcelDraft::body); невідома назва дає відмову без позначки поля.
     */
    private const array FIELDS = [
        'recipient.phone' => 'phone',
        'recipient.last_name' => 'last_name',
        'recipient.first_name' => 'first_name',
        'recipient.middle_name' => 'middle_name',
        'recipient.department' => 'point',
        'recipient.city' => 'point',
        'params.weight' => 'weight',
        'params.length' => 'sides',
        'params.width' => 'sides',
        'params.height' => 'sides',
        'params.volume' => 'sides',
        'insurance_cost' => 'declared_value',
        'cost' => 'cod_amount',
        'delivery_payer' => 'payer',
        'description' => 'description',
    ];

    /**
     * @param RozetkaApiException|string $problem відмова Rozetka або мовний ключ власної відмови модуля
     * @return list<ParcelIssue>
     */
    public function explain(RozetkaApiException|string $problem): array
    {
        if (is_string($problem)) {
            return [new ParcelIssue(null, $problem)];
        }

        $key = match ($problem->kind) {
            ApiErrorKind::BadToken => 'parcel_api_bad_token',
            ApiErrorKind::NotConfigured => 'parcel_api_not_configured',
            ApiErrorKind::RateLimited => 'parcel_api_rate_limited',
            ApiErrorKind::NotFound => 'parcel_api_not_found',
            ApiErrorKind::Unreachable => 'parcel_api_unreachable',
            ApiErrorKind::ServerError => 'parcel_api_server',
            ApiErrorKind::Malformed, ApiErrorKind::Empty => 'parcel_api_malformed',
            ApiErrorKind::Rejected => null,
        };
        if ($key !== null) {
            $advice = match ($problem->kind) {
                ApiErrorKind::BadToken, ApiErrorKind::NotConfigured => self::PREFIX . 'parcel_advice_key',
                ApiErrorKind::RateLimited => self::PREFIX . 'parcel_advice_retry',
                default => null,
            };

            return [new ParcelIssue(null, self::PREFIX . $key, $advice)];
        }

        return [new ParcelIssue(self::field($problem->field), $problem->apiMessage ?? self::PREFIX . 'parcel_api_rejected')];
    }

    public static function field(?string $property): ?string
    {
        if ($property === null) {
            return null;
        }
        // `data.recipient.phone.0` і `recipient.phone[0]` — те саме поле.
        $name = strtolower((string)preg_replace(['/^data\./', '/\[\d+\]/', '/\.\d+(?=\.|$)/'], '', $property));
        if (str_starts_with($name, 'sender.')) {
            return 'sender';
        }

        return self::FIELDS[$name] ?? null;
    }
}
