<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\BackendTranslations;
use Okay\Core\Modules\Modules;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelFile;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelIssue;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\Labels;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelDrafts;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssue;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelLookup;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelOutcome;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelRows;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelService;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelTracking;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\TrackNumber;

/**
 * Блок ЕН на сторінці замовлення надсилає сюди (`controller=Sviat.RozetkaDelivery.ParcelAdmin`).
 * Доступ потребує права `orders`; кожна дія — POST із CSRF-токеном. Етикетка йде через цей контролер:
 * запит до Rozetka несе токен, тож браузер не бачить ні його, ні відповіді API.
 */
class ParcelAdmin extends IndexAdmin
{
    private const string KEY = 'sviat__rozetka__';
    /** Відмови, де value — id іншого замовлення магазину: менеджер відкриває його, а не шукає. */
    private const array ORDER_LINKS = ['sviat__rozetka__parcel_err_track_elsewhere'];

    public function fetch(
        ParcelService $service,
        ParcelDrafts $drafts,
        ParcelLookup $lookup,
        ParcelTracking $tracking,
        Labels $labels,
        ParcelRows $rows,
        BackendTranslations $translations,
        OrderDeliveryLines $lines,
        Modules $modules,
    ) {
        if (!$this->request->method('post')) {
            $this->answer(405, ['ok' => false]);

            return;
        }
        // Сток 4.5.3 перевіряє session_id лише після виконання контролера.
        if (!$this->request->checkSession()) {
            $this->answer(403, ['ok' => false]);

            return;
        }
        // Адмінка виконує init() і вимкнених модулів, тож маршрут лишається досяжним.
        if (!$modules->isActiveModule('Sviat', 'RozetkaDelivery')) {
            $this->answer(200, self::view(ParcelOutcome::refused(self::KEY . 'parcel_err_module_off'), $translations));

            return;
        }
        $orderId = (int)$this->request->post('order_id', 'integer');
        $order = $orderId > 0 ? $this->entityFactory->get(OrdersEntity::class)->get($orderId) : null;
        if (!$order) {
            $this->answer(404, ['ok' => false]);

            return;
        }

        $action = (string)$this->request->post('action');
        if ($action === 'label') {
            $this->label($labels, $rows, $translations, $orderId);

            return;
        }
        // Нова ЕН — лише для замовлення, яке досі доставляє ROZETKA; наявну можна видалити чи відв'язати й надалі.
        if (in_array($action, ['create', 'attach'], true) && $lines->ownDelivery($order) === null) {
            $this->answer(200, self::view(ParcelOutcome::refused(self::KEY . 'parcel_err_not_own'), $translations));

            return;
        }
        $outcome = match ($action) {
            'create' => $this->create($service, $drafts, $orderId),
            'lookup' => $lookup->find($orderId),
            'choose' => $this->choose($lookup, $orderId),
            'attach' => $service->attach($orderId, (string)$this->request->post('track')),
            'delete' => $service->delete($orderId),
            'unlink' => $service->unlink($orderId),
            'refresh' => $tracking->refresh($orderId),
            default => null,
        };
        if ($outcome === null) {
            $this->answer(400, ['ok' => false]);

            return;
        }

        $this->answer(200, self::view($outcome, $translations));
    }

    private function create(ParcelService $service, ParcelDrafts $drafts, int $orderId): ParcelOutcome
    {
        $draft = $drafts->forOrder($orderId);
        if (is_string($draft)) {
            return ParcelOutcome::refused($draft);
        }
        $post = [];
        foreach ($_POST as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $post[$name] = $value;
            }
        }

        return $service->create($orderId, $draft->withForm($post));
    }

    private function choose(ParcelLookup $lookup, int $orderId): ParcelOutcome
    {
        $trackId = TrackNumber::normalize((string)$this->request->post('track'));

        return $trackId === null ? ParcelOutcome::refused(self::KEY . 'parcel_err_lookup_gone') : $lookup->choose($orderId, $trackId);
    }

    private function label(Labels $labels, ParcelRows $rows, BackendTranslations $translations, int $orderId): void
    {
        $trackId = ParcelService::track($rows->forOrder($orderId)->track_id ?? null);
        $file = $trackId === null ? new LabelIssue(self::KEY . 'parcel_err_none') : $labels->many([$trackId]);
        if ($file instanceof LabelIssue) {
            $this->answer(200, self::view(ParcelOutcome::failed([new ParcelIssue(null, $file->text, $file->advice)]), $translations));

            return;
        }
        $this->sendFile($file);
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

    /**
     * Тексти вже перекладені: мовний ключ модуля перекладається, текст Rozetka іде як є (JS вставляє його через textContent).
     *
     * @return array<string, mixed>
     */
    private static function view(ParcelOutcome $outcome, BackendTranslations $translations): array
    {
        $text = static fn(?string $key): ?string => $key === null ? null
            : (str_starts_with($key, self::KEY) ? (string)$translations->getTranslation($key) : $key);

        return [
            'ok' => $outcome->ok,
            'track' => $outcome->trackId,
            'issues' => array_map(static fn(ParcelIssue $issue): array => [
                'field' => $issue->field,
                'text' => trim($text($issue->text) . ' ' . (string)$outcome->value),
                'advice' => $text($issue->advice),
                'orderUrl' => in_array($issue->text, self::ORDER_LINKS, true) && ctype_digit((string)$outcome->value)
                    ? 'index.php?controller=OrderAdmin&id=' . (int)$outcome->value : null,
            ], $outcome->issues),
            'candidates' => array_map(static fn(array $c): array => [
                'track' => $c['track_id'],
                'date' => $c['date'],
                'receiver' => $c['receiver'],
                'cost' => $c['cost'],
            ], $outcome->candidates),
        ];
    }

    private function answer(int $status, array $body): void
    {
        $this->response->setStatusCode($status);
        $this->response->setContent(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), RESPONSE_JSON);
    }
}
