<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\Modules\Modules;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelFile;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionPrint;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionRows;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionService;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionStatus;

/**
 * Реєстри передачі за 30 днів (`controller=Sviat.RozetkaDelivery.ReceptionAdmin`), право `orders`.
 * Друк, оновлення, видалення й «сформувати з усіх готових» — лише POST із CSRF-токеном.
 */
class ReceptionAdmin extends IndexAdmin
{
    private const int DAYS = 30;
    private const string KEY = 'sviat__rozetka__';

    public function fetch(
        ReceptionRows $receptions,
        ReceptionService $service,
        ReceptionPrint $print,
        Modules $modules,
    ) {
        $message = null;
        if ($this->request->method('post')) {
            // Сток 4.5.3 перевіряє session_id лише після виконання контролера, тож підроблений POST уже встиг би спрацювати.
            if (!$this->request->checkSession()) {
                $this->response->setStatusCode(403);
                $this->response->setContent('Session expired', RESPONSE_TEXT);

                return;
            }
            // Адмінка виконує init() і вимкнених модулів, тож маршрут лишається досяжним.
            if (!$modules->isActiveModule('Sviat', 'RozetkaDelivery')) {
                $message = ['ok' => false, 'key' => self::KEY . 'parcel_err_module_off', 'details' => null, 'rejected' => [], 'skipped' => []];
            } else {
                $receptionId = (int)$this->request->post('reception_id', 'integer');
                $action = (string)$this->request->post('action');
                // Реєстр, сформований поза магазином (у кабінеті), модуль не друкує, не оновлює й не видаляє.
                if ($action !== 'create_ready' && $receptions->get($receptionId) === null) {
                    $message = self::message(false, self::KEY . 'reception_err_unknown');
                } elseif ($action === 'print') {
                    $file = $print->full($receptionId);
                    if ($file instanceof LabelFile) {
                        $this->sendFile($file);

                        return;
                    }
                    $message = self::message(false, $file->text);
                } else {
                    $message = match ($action) {
                        'refresh' => self::fromResult($service->refresh($receptionId), 'reception_done_refresh'),
                        'delete' => self::fromResult($service->delete($receptionId), 'reception_done_delete'),
                        'create_ready' => self::fromCreate($service->createFromReady()),
                        default => null,
                    };
                }
            }
        }

        $since = date('Y-m-d H:i:s', time() - self::DAYS * 86400);
        $this->design->assign('rozetka_receptions', array_map(static fn(object $row): array => [
            'id' => (int)$row->reception_id,
            'status' => ReceptionStatus::of($row->status ?? null),
            'open' => ReceptionStatus::isOpen(ReceptionStatus::of($row->status ?? null)),
            'amount' => (int)($row->tracks_amount ?? 0),
            'created' => (string)($row->created_at ?? ''),
            'checked' => (string)($row->checked_at ?? ''),
        ], $receptions->recent($since)));
        $this->design->assign('rozetka_reception_message', $message);
        $this->response->setContent($this->design->fetch('receptions.tpl'));
    }

    /** Лише байти, що пройшли LabelFile::pdf(): `attachment` і `nosniff` не дають браузеру виконати їх як сторінку. */
    private function sendFile(LabelFile $file): void
    {
        $this->response->setStatusCode(200);
        $this->response->addHeader('Content-Type: ' . $file->mime);
        $this->response->addHeader('Content-Disposition: attachment; filename="' . $file->filename . '"');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->addHeader('X-Content-Type-Options: nosniff');
        $this->response->setContent($file->bytes, RESPONSE_TEXT);
    }

    /** Текст Rozetka (не ключ модуля) показується деталлю під загальною відмовою. */
    private static function message(bool $ok, ?string $text, array $rejected = [], array $skipped = [], ?string $value = null): array
    {
        $own = $text === null || str_starts_with($text, self::KEY);

        return [
            'ok' => $ok,
            'key' => $own ? $text : self::KEY . 'parcel_api_rejected',
            'details' => $own ? null : $text,
            'value' => $value,
            'rejected' => $rejected,
            'skipped' => $skipped,
        ];
    }

    /** @param array{ok: bool, error: ?string} $result */
    private static function fromResult(array $result, string $done): array
    {
        return $result['ok'] ? self::message(true, self::KEY . $done) : self::message(false, $result['error']);
    }

    private static function fromCreate(array $result): array
    {
        if ($result['reception_id'] === null) {
            return self::message(false, $result['error'], [], $result['skipped']);
        }

        return self::message(true, self::KEY . 'mass_reception_title', $result['rejected'], $result['skipped'], (string)$result['reception_id']);
    }
}
