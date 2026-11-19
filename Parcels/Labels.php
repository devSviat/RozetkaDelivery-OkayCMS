<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Psr\Log\LoggerInterface;

/**
 * Етикетки ЕН: `GET track/label` віддає base64 у JSON, а байти PDF — адмін-контролер, тож токен не потрапляє
 * в браузер. Чи це один PDF на всі номери чи по файлу на номер, не відомо (М8): рядок і масив приймаються обидва.
 * Вміст відповіді ніколи не логується.
 */
final class Labels
{
    public const int MAX = 20;

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly RozetkaClient $client,
        private readonly LoggerInterface $logger,
        private readonly \Closure $now,
    ) {
    }

    /** @param list<string> $trackIds */
    public function many(array $trackIds): LabelFile|LabelIssue
    {
        $ids = array_values(array_unique(array_filter(array_map(ParcelService::track(...), $trackIds))));
        if ($ids === [] || count($ids) !== count(array_unique($trackIds)) || count($ids) > self::MAX) {
            return new LabelIssue('sviat__rozetka__label_err_count');
        }
        try {
            $data = $this->client->get('track/label', ['id' => $ids])['data'];
        } catch (RozetkaApiException $e) {
            $issue = new ParcelIssues()->explain($e)[0];

            return new LabelIssue($issue->text, $issue->advice);
        }
        $label = is_array($data) ? ($data['label'] ?? null) : null;
        $parts = is_array($label) && array_is_list($label) ? $label : [$label];
        $pdfs = [];
        foreach ($parts as $part) {
            $pdf = LabelFile::pdf($part);
            if ($pdf === null) {
                $this->logger->warning('Rozetka label is not a PDF: ' . LabelFile::describe($part));

                return new LabelIssue('sviat__rozetka__label_err_not_pdf');
            }
            $pdfs[] = $pdf;
        }
        $name = count($ids) === 1 ? "rozetka-{$ids[0]}" : 'rozetka-labels-' . date('Ymd-His', ($this->now)());

        return LabelFile::fromPdfs($pdfs, $name);
    }
}
