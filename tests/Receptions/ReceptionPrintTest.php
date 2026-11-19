<?php

namespace Modules\Sviat\RozetkaDelivery\Receptions;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Parcels/LabelsTest.php';

use Modules\Sviat\RozetkaDelivery\Parcels\LabelsTest;
use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelFile;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelIssue;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionPrint;
use PHPUnit\Framework\TestCase;

/** `GET /api/reception/print/{id}?type=full` → {reception_id, url, base64}; приклад base64 у схемі — SVG. */
class ReceptionPrintTest extends TestCase
{
    /** Приклад `base64` зі схеми: `<svg width="100" height="50" …`. */
    private const string SCHEMA_SVG = 'PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjUwIiB4bWxucz0iaHR0cD=';

    private ApiWorld $world;

    protected function setUp(): void
    {
        $this->world = new ApiWorld();
    }

    private function print(): ReceptionPrint
    {
        return new ReceptionPrint($this->world->client(), $this->world->logger);
    }

    public function testPdfOnly(): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['reception_id' => 555, 'url' => 'https://example.invalid/x', 'base64' => base64_encode(LabelsTest::PDF)]]);

        $file = $this->print()->full(555);

        self::assertInstanceOf(LabelFile::class, $file);
        self::assertSame(LabelsTest::PDF, $file->bytes);
        self::assertSame('rozetka-reception-555.pdf', $file->filename);
        self::assertStringEndsWith('/api/reception/print/555?type=full', $this->world->http->requests()[0]->url);
    }

    public function testSvgRefused(): void
    {
        // Приклад схеми обрізаний і як base64 не розбирається; той самий SVG цілим — справжня перевірка.
        foreach ([self::SCHEMA_SVG, base64_encode('<svg width="100" height="50" xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>')] as $base64) {
            $this->world->http->json(200, ['statusCode' => 0, 'data' => ['reception_id' => 555, 'url' => 'https://example.invalid/x', 'base64' => $base64]]);

            $issue = $this->print()->full(555);

            self::assertInstanceOf(LabelIssue::class, $issue);
            self::assertSame('sviat__rozetka__reception_err_not_pdf', $issue->text);
        }
    }

    public function testUrlIgnored(): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['reception_id' => 555, 'url' => 'https://example.invalid/reception.pdf']]);

        self::assertSame('sviat__rozetka__reception_err_not_pdf', $this->print()->full(555)->text);
        self::assertCount(1, $this->world->http->requests(), 'the URL from the answer is never fetched');
    }
}
