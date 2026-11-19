<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Orders;

use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelFile;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelIssue;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\Labels;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\MassParcels;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\MassReport;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelService;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionService;
use Psr\Log\LoggerInterface;

/**
 * Дії списку замовлень «створити ЕН», «надрукувати етикетки» й «сформувати реєстр». Ядро не передає дії списку жодному модулю,
 * тож надіслану дію беруть із хука фільтра самого списку; звіт чекає в сесії на сторінку, на яку повертають браузер.
 */
final class OrdersMassAction
{
    public const string CREATE = 'sviat_rozetka_create_parcels';
    public const string LABELS = 'sviat_rozetka_print_labels';
    public const string RECEPTION = 'sviat_rozetka_create_reception';
    private const array ACTIONS = [self::CREATE, self::LABELS, self::RECEPTION];
    private const string SESSION_KEY = 'sviat_rozetka_mass';
    private const string KEY = 'sviat__rozetka__';

    public function __construct(
        private readonly MassParcels $mass,
        private readonly Labels $labels,
        private readonly ParcelRows $rows,
        private readonly LoggerInterface $logger,
        private readonly ReceptionService $receptions,
    ) {
    }

    /**
     * @param bool $sessionOk CSRF-токен адміна збігся: сток перевіряє його лише після контролера
     * @return bool|LabelFile false — дія не цього модуля; true — сторінку перезавантажити редиректом; файл — віддати браузеру
     */
    public function handle(array $post, bool $sessionOk, array &$session): bool|LabelFile
    {
        $action = $post['action'] ?? null;
        if (!in_array($action, self::ACTIONS, true) || !is_array($post['check'] ?? null) || !$sessionOk) {
            return false;
        }
        if ($action === self::CREATE) {
            // Закрита вкладка не має обривати запит між резервом і відповіддю Rozetka.
            ignore_user_abort(true);
            $session[self::SESSION_KEY] = ['action' => self::CREATE, 'report' => $this->mass->run($post['check'])->toArray()];

            return true;
        }

        if ($action === self::RECEPTION) {
            $session[self::SESSION_KEY] = $this->reception(MassParcels::ids($post['check']));

            return true;
        }

        return $this->print(MassParcels::ids($post['check']), $session);
    }

    /**
     * Причина відмови Rozetka — значення рядка звіту, а не ключ: шаблон її екранує.
     *
     * @param list<int> $selected
     * @return array<string, mixed> дані сесії
     */
    private function reception(array $selected): array
    {
        $ids = array_slice($selected, 0, ReceptionService::LIMIT);
        $result = $this->receptions->create($ids);
        $rows = [];
        foreach ($result['skipped'] as $skipped) {
            $rows[] = ['orderId' => $skipped['order_id'], 'kind' => 'skipped', 'key' => $skipped['key'], 'value' => null];
        }
        $orderOf = [];
        foreach ($this->rows->forOrders($ids) as $orderId => $row) {
            $orderOf[(string)$row->track_id] = (int)$orderId;
        }
        foreach ($result['rejected'] as $rejected) {
            $rows[] = ['orderId' => $orderOf[$rejected['track_id']] ?? 0, 'kind' => 'failed', 'key' => self::KEY . 'reception_rejected', 'value' => $rejected['reason']];
        }
        $error = $result['error'];
        $own = $error === null || str_starts_with($error, self::KEY);

        return [
            'action' => self::RECEPTION,
            'report' => new MassReport($rows, count($selected) - count($ids))->toArray(),
            'receptionId' => $result['reception_id'],
            'error' => $error === null ? null : ['key' => $own ? $error : self::KEY . 'parcel_api_rejected', 'details' => $own ? null : $error],
        ];
    }

    /** @param list<int> $selected */
    private function print(array $selected, array &$session): bool|LabelFile
    {
        $ids = array_slice($selected, 0, Labels::MAX);
        $parcels = $this->rows->forOrders($ids);
        $tracks = [];
        $rows = [];
        foreach ($ids as $orderId) {
            $trackId = ParcelService::track($parcels[$orderId]->track_id ?? null);
            if ($trackId === null || ($parcels[$orderId]->state ?? null) !== 'created') {
                $rows[] = ['orderId' => $orderId, 'kind' => 'skipped', 'key' => self::KEY . 'parcel_err_none', 'value' => null];
                continue;
            }
            $tracks[] = $trackId;
        }
        $data = ['action' => self::LABELS, 'report' => new MassReport($rows, count($selected) - count($ids))->toArray(), 'printOf' => count($ids)];
        if ($tracks === []) {
            $session[self::SESSION_KEY] = $data + ['error' => ['key' => self::KEY . 'label_err_none', 'details' => null]];

            return true;
        }
        try {
            $file = $this->labels->many($tracks);
        } catch (\Throwable $e) {
            // Замість 500 менеджер повертається до списку з повідомленням. У лог — лише клас: текст може нести дані запиту.
            $this->logger->error('Rozetka mass label print failed: ' . $e::class);
            $file = new LabelIssue(self::KEY . 'mass_print_generic');
        }
        if ($file instanceof LabelIssue) {
            // Текст Rozetka — лише деталь під загальною відмовою: ключем у сесії може бути тільки мовний ключ модуля.
            $own = str_starts_with($file->text, self::KEY);
            $session[self::SESSION_KEY] = $data + ['error' => [
                'key' => $own ? $file->text : self::KEY . 'parcel_api_rejected',
                'details' => $own ? null : $file->text,
            ]];

            return true;
        }
        // Браузер бере з відповіді лише файл: пропущені замовлення список покаже, коли його відкриють знову.
        if ($rows !== [] || count($selected) > count($ids)) {
            $session[self::SESSION_KEY] = $data;
        }

        return $file;
    }

    /**
     * handle() для поточного запиту. Сток 4.5.3 checkSession() очищає $_POST за хибного токена,
     * тому POST читається до перевірки.
     *
     * @param \Closure(): bool $checkSession
     */
    public function handleRequest(\Closure $checkSession, array &$session): bool|LabelFile
    {
        $post = $_POST;

        return $this->handle($post, (bool)$checkSession(), $session);
    }

    /** @return ?array{action: string, report: ?MassReport, error: ?array{key: string, details: ?string}, printOf: ?int, receptionId: ?int} */
    public static function take(array &$session): ?array
    {
        if (!array_key_exists(self::SESSION_KEY, $session)) {
            return null;
        }
        $data = $session[self::SESSION_KEY];
        unset($session[self::SESSION_KEY]);
        $action = is_array($data) ? ($data['action'] ?? null) : null;
        if (!in_array($action, self::ACTIONS, true)) {
            return null;
        }
        $report = isset($data['report']) ? MassReport::fromArray($data['report']) : null;
        $error = $data['error'] ?? null;
        $error = is_array($error) && is_string($error['key'] ?? null) && str_starts_with($error['key'], self::KEY)
            && (($error['details'] ?? null) === null || is_string($error['details']))
            ? ['key' => $error['key'], 'details' => $error['details'] ?? null]
            : null;
        $printOf = is_int($data['printOf'] ?? null) ? $data['printOf'] : null;
        $receptionId = is_int($data['receptionId'] ?? null) ? $data['receptionId'] : null;

        return $report === null && $error === null ? null : ['action' => $action, 'report' => $report, 'error' => $error, 'printOf' => $printOf, 'receptionId' => $receptionId];
    }

    /**
     * @param array{action: string, report: ?MassReport, error: ?array{key: string, details: ?string}, printOf: ?int, receptionId?: ?int} $taken
     * @param \Closure(string): string $text повний мовний ключ → текст
     * @return array{title: ?string, rows: list<array{orderId: int, kind: string, text: string}>, untouched: ?string, error: ?string}
     */
    public static function view(array $taken, \Closure $text): array
    {
        $report = $taken['report'];
        $error = $taken['error'];
        $rows = $report === null ? [] : array_map(static function (array $row) use ($text): array {
            $value = (string)($row['value'] ?? '');
            $line = match ($row['kind']) {
                'created' => sprintf($text(self::KEY . 'mass_created'), $value),
                'skipped' => $text(self::KEY . 'mass_skipped') . ': ' . $text((string)$row['key']) . ($value !== '' ? ' ' . $value : ''),
                default => $text(self::KEY . 'mass_failed') . ': ' . $text((string)$row['key']) . ($value !== '' ? ' (' . $value . ')' : ''),
            };

            return ['orderId' => $row['orderId'], 'kind' => $row['kind'], 'text' => $line];
        }, $report->rows);

        $title = null;
        if ($taken['action'] === self::RECEPTION) {
            $title = ($taken['receptionId'] ?? null) !== null ? sprintf($text(self::KEY . 'mass_reception_title'), (string)$taken['receptionId']) : null;
        } elseif ($report !== null && $taken['action'] === self::LABELS) {
            $of = $taken['printOf'] ?? $report->processed();
            $title = sprintf($text(self::KEY . 'mass_print_title'), max(0, $of - $report->processed()), $of);
        } elseif ($report !== null) {
            $title = sprintf($text(self::KEY . 'mass_title'), $report->created(), $report->processed());
        }

        return [
            'title' => $title,
            'rows' => $rows,
            'untouched' => $report !== null && $report->untouched > 0
                ? sprintf($text(self::KEY . 'mass_untouched'), $taken['action'] === self::RECEPTION ? ReceptionService::LIMIT : MassParcels::LIMIT, $report->untouched)
                : null,
            'error' => $error === null ? null : trim($text($error['key']) . ' ' . (string)$error['details']),
        ];
    }
}
