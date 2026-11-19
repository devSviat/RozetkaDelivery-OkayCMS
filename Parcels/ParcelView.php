<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionRows;

/**
 * Що показує блок ЕН на сторінці замовлення: форму нової, розбір невідомого створення або картку наявної.
 * `actions` — кнопки, які шаблон малює; ParcelAdmin усе одно перевіряє кожну дію сам.
 */
final class ParcelView
{
    /** @param \Closure(): int $now той самий годинник, що й у ParcelService: картка й дії однаково бачать застарілий резерв */
    public function __construct(
        private readonly OrderDeliveryLines $lines,
        private readonly ParcelRows $rows,
        private readonly ParcelDrafts $drafts,
        private readonly AuthFlags $auth,
        private readonly CodSettings $codSettings,
        private readonly \Closure $now,
        private readonly ReceptionRows $receptions,
    ) {
    }

    /** @return ?array<string, mixed> null — блок не показується: доставка не ROZETKA і ЕН немає */
    public function forOrder(object $order): ?array
    {
        $orderId = (int)($order->id ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        $row = $this->rows->forOrder($orderId);
        $receptionId = (int)($row->reception_id ?? 0);

        return self::build(
            $orderId,
            $row,
            $this->lines->ownDelivery($order) !== null,
            fn(): ParcelDraft|string => $this->drafts->forOrder($orderId),
            $this->auth->items(),
            ($this->now)(),
            $this->codSettings->read()->fiscal,
            $receptionId > 0 ? $this->receptions->get($receptionId) : null,
        );
    }

    /**
     * @param \Closure(): (ParcelDraft|string) $draft чернетка потрібна лише формі, а будується з товарів замовлення
     * @param list<array{api: string, reason: string}> $auth AuthFlags::items()
     * @param string $fiscal CodConfig::FISCAL_*
     * @param ?object $reception рядок ReceptionEntity реєстру ЕН
     * @return ?array<string, mixed>
     */
    public static function build(int $orderId, ?object $row, bool $own, \Closure $draft, array $auth, int $now, string $fiscal = CodConfig::FISCAL_UNKNOWN, ?object $reception = null): ?array
    {
        $view = self::state($orderId, $row, $own, $draft, $now, $fiscal, $reception);
        if ($view === null) {
            return null;
        }

        // Smarty 5 не викликає in_array() без реєстрації: шаблон питає `can.<дія>`.
        return $view + ['can' => array_fill_keys($view['actions'], true), 'auth' => $auth];
    }

    /**
     * @param \Closure(): (ParcelDraft|string) $draft
     * @return ?array<string, mixed>
     */
    private static function state(int $orderId, ?object $row, bool $own, \Closure $draft, int $now, string $fiscal, ?object $reception): ?array
    {
        // Після зміни способу доставки ЕН старої точки лишається видимою: її треба видалити чи відв'язати.
        if ($row === null && !$own) {
            return null;
        }
        $view = ['orderId' => $orderId, 'mode' => 'form', 'actions' => [], 'card' => null, 'form' => null, 'issues' => [], 'refusalKey' => null];

        if (PendingParcel::needsCheck($row, $now)) {
            return ['mode' => 'check', 'actions' => ['lookup', 'attach', 'unlink']] + $view;
        }
        if (PendingParcel::running($row, $now)) {
            return ['mode' => 'pending', 'refusalKey' => 'sviat__rozetka__parcel_err_pending'] + $view;
        }
        if ($row !== null) {
            $actions = ['label', 'refresh', ...(ParcelService::deletable($row) ? ['delete'] : []), 'unlink'];

            return ['mode' => 'card', 'card' => ParcelCard::fromRow($row, $fiscal, $reception, $now), 'actions' => $actions] + $view;
        }

        $built = $draft();
        if (is_string($built)) {
            return ['mode' => 'refusal', 'refusalKey' => $built, 'actions' => ['attach']] + $view;
        }
        $issues = [];
        foreach ($built->errors() as $error) {
            $issues[$error['field']] ??= $error['key'];
        }

        return ['form' => self::form($built, $fiscal), 'issues' => $issues, 'actions' => ['create', 'attach']] + $view;
    }

    /** Як у кошику: назви точок Rozetka часто вже містять адресу, і вдруге вона не дописується. */
    public static function pointLine(?object $point): string
    {
        $title = trim((string)($point->title ?? ''));
        $address = trim((string)($point->address ?? ''));
        if ($address === '' || str_contains($title, $address)) {
            return $title;
        }

        return $title === '' ? $address : $title . ', ' . $address;
    }

    /** @return array<string, mixed> */
    private static function form(ParcelDraft $draft, string $fiscal): array
    {
        $point = $draft->point;

        return [
            'lastName' => $draft->lastName,
            'firstName' => $draft->firstName,
            'middleName' => $draft->middleName,
            'phone' => $draft->phone,
            'point' => self::pointLine($point),
            'prepayOnly' => (int)($point->prepay_only ?? 0) === 1,
            'weight' => rtrim(rtrim(number_format($draft->weightKg, 3, '.', ''), '0'), '.'),
            'length' => (string)$draft->lengthCm,
            'width' => (string)$draft->widthCm,
            'height' => (string)$draft->heightCm,
            'declared' => number_format($draft->declaredValue, 2, '.', ''),
            'costLimit' => number_format($draft->costLimit(), 0, '.', ''),
            'payer' => $draft->payer,
            'payerReason' => $draft->payerReason,
            'codAllowed' => $draft->codAllowed,
            'codAmount' => number_format($draft->codAmount, 2, '.', ''),
            'codFee' => $draft->codFeeEstimate !== null ? number_format($draft->codFeeEstimate, 2, '.', '') : null,
            'fiscalBanner' => $draft->codAllowed && $fiscal === CodConfig::FISCAL_UNKNOWN,
            'awaitsPayment' => $draft->awaitsPayment(),
            'description' => $draft->description,
        ];
    }
}
