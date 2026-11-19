<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Receptions;

use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelFile;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelIssue;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Psr\Log\LoggerInterface;

/**
 * Друк реєстру: лише PDF із поля `base64`. Схема показує там і SVG, а SVG на домені адмінки — виконуваний скрипт.
 * Поле `url` не відкривається й не стає редиректом: адреса з відповіді може вести будь-куди.
 */
final class ReceptionPrint
{
    public function __construct(
        private readonly RozetkaClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function full(int $receptionId): LabelFile|LabelIssue
    {
        if ($receptionId <= 0) {
            return new LabelIssue('sviat__rozetka__reception_err_none');
        }
        try {
            $data = $this->client->get('reception/print/' . $receptionId, ['type' => 'full'])['data'];
        } catch (RozetkaApiException $e) {
            return new LabelIssue(new ParcelIssues()->explain($e)[0]->text);
        }
        $base64 = is_array($data) ? ($data['base64'] ?? null) : null;
        $pdf = LabelFile::pdf($base64);
        if ($pdf === null) {
            $this->logger->warning('Rozetka reception print is not a PDF: ' . LabelFile::describe($base64));

            return new LabelIssue('sviat__rozetka__reception_err_not_pdf');
        }

        return new LabelFile($pdf, 'application/pdf', "rozetka-reception-{$receptionId}.pdf");
    }
}
