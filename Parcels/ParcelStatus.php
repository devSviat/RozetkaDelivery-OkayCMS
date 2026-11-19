<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\StatusDictionary;

/**
 * Групи статусів ЕН для бейджів, фільтра й трекінгу (спека §12). Статус приходить числом (словник, реєстр) або
 * рядком camelCase (схема `last_status`); як вони відповідають одне одному, Rozetka не описує, тож мапа рядків
 * зібрана за назвами й підтверджується вживу (М3). Невідомий статус групу не змінює.
 */
final class ParcelStatus
{
    public const array GROUPS = ['created', 'accepted', 'in_transit', 'arrived', 'refused', 'returning', 'received', 'returned', 'cancelled', 'problem'];

    /** З ЕН більше нічого не станеться: трекінг її не опитує. */
    public const array FINAL = ['received', 'returned', 'cancelled'];

    /** Групи фільтра списку замовлень; `none` — замовлення без ЕН. */
    public const array FILTER_GROUPS = [...self::GROUPS, 'none'];

    /** Група словника Rozetka → група модуля: новий код у відомій групі розпізнається без релізу. */
    private const array ROZETKA_GROUPS = [
        100 => 'created', 200 => 'accepted', 300 => 'in_transit', 400 => 'cancelled', 500 => 'arrived',
        600 => 'refused', 700 => 'refused', 800 => 'refused', 900 => 'returning', 1000 => 'returning', 1100 => 'returning',
        1200 => 'received', 1300 => 'returned', 1400 => 'problem',
    ];

    /** Коди, відомі на 2026-11-18: працюють і до першого завантаження словника. */
    private const array CODE_GROUPS = [
        'created' => [10010, 10020],
        'accepted' => [10028, 10030],
        'in_transit' => [10040, 10050, 10070, 20010, 20020, 20030, 30010, 40041],
        'cancelled' => [10060, 60010],
        'arrived' => [40030, 40040, 40080],
        'refused' => [40050, 40060, 40070],
        'returning' => [50010, 40045, 50011, 50012, 50013, 50015, 50021, 50030, 50020],
        'received' => [60025, 60030],
        'returned' => [60040],
        // «Не прийнято за реєстром» у групі 200 словника, але для магазину це проблема, а не прийом.
        'problem' => [10080, 10029],
    ];

    /** Рядок схеми → код, за назвою (спека §12.2). */
    public const array STRING_CODES = [
        'planned' => 10010,
        'readyForOctopus' => 10020,
        'acceptedFromReception' => 10028,
        'notAcceptedFromReception' => 10029,
        'acceptedFromMerchant' => 10030,
        'acceptedByCarrier' => 10070,
        'lost' => 10080,
        'forwarding' => 20030,
        'cancelled' => 10060,
        'acceptedInDepartment' => 40030,
        'readyToIssueOctopus' => 40040,
        'storageDateIncreased' => 40080,
        'unsuccessfulDelivery' => 40045,
        'clientCanceled' => 40050,
        'senderCanceled' => 40060,
        'storageDateExpired' => 40070,
        'senderPending' => 50020,
        'gaveOutPartially' => 60025,
        'gaveOut' => 60030,
        'returned' => 60040,
    ];

    /** Рядки схеми без відомого коду — одразу група модуля. */
    public const array STRING_GROUPS = [
        'placedInBatch' => 'in_transit',
        'readyToCarrier' => 'in_transit',
        'inTheWay' => 'in_transit',
        'acceptedOnDistributionCenter' => 'in_transit',
        'arrivedToDistributionCenter' => 'in_transit',
        'containerOnAcceptance' => 'in_transit',
        'placedInStore' => 'arrived',
        'wrongLocation' => 'problem',
        'wrongReturnLocation' => 'problem',
        'readyToReturn' => 'returning',
        'packingReturn' => 'returning',
        'acceptedForCourierReturn' => 'returning',
        'acceptedOnDistributionCenterReturn' => 'returning',
        'inTheWayReturn' => 'returning',
        'returnsAcceptance' => 'returning',
        'gaveOutForShowcase' => 'received',
        'toCancel' => 'cancelled',
    ];

    private const int PARTIAL = 60025;

    /** Код статусу: число як є, рядок — за STRING_CODES; null — рядок без коду чи сміття. */
    public static function code(int|string|null $status): ?int
    {
        if (is_int($status)) {
            return $status > 0 ? $status : null;
        }
        if ($status === null) {
            return null;
        }
        if (ctype_digit($status)) {
            return (int)$status > 0 ? (int)$status : null;
        }

        return self::STRING_CODES[$status] ?? null;
    }

    public static function group(int|string|null $status, ?StatusDictionary $dictionary = null): ?string
    {
        $code = self::code($status);
        if ($code === null) {
            return is_string($status) ? (self::STRING_GROUPS[$status] ?? null) : null;
        }
        foreach (self::CODE_GROUPS as $group => $codes) {
            if (in_array($code, $codes, true)) {
                return $group;
            }
        }
        $rozetkaGroup = $dictionary?->groupOf($code);

        return $rozetkaGroup === null ? null : (self::ROZETKA_GROUPS[$rozetkaGroup] ?? null);
    }

    /** Статус відомий модулю: невідомий записується в `unknown_statuses`, а група лишається. */
    public static function known(int|string|null $status, ?StatusDictionary $dictionary = null): bool
    {
        return self::group($status, $dictionary) !== null;
    }

    public static function partial(int|string|null $status): bool
    {
        return self::code($status) === self::PARTIAL;
    }

    public static function isFinal(?string $group): bool
    {
        return in_array($group, self::FINAL, true);
    }
}
