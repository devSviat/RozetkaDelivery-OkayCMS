<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

require_once __DIR__ . '/../Support/ApiWorld.php';

use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelFile;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelIssue;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\Labels;
use PHPUnit\Framework\TestCase;

/** `GET /api/track/label?id[]=…` → `{statusCode, data{label: "<base64>"}}`. */
class LabelsTest extends TestCase
{
    public const string PDF = "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF";
    private const string TRACK = '101123456789';

    private ApiWorld $world;

    protected function setUp(): void
    {
        $this->world = new ApiWorld();
    }

    private function labels(): Labels
    {
        return new Labels($this->world->client(), $this->world->logger, $this->world->clock());
    }

    private function answer(mixed $label): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['label' => $label]]);
    }

    public function testPdfReturned(): void
    {
        $this->answer(base64_encode(self::PDF));

        $file = $this->labels()->many([self::TRACK, '101123456790']);

        self::assertInstanceOf(LabelFile::class, $file);
        self::assertSame(self::PDF, $file->bytes);
        self::assertSame('application/pdf', $file->mime);
        self::assertMatchesRegularExpression('/^rozetka-[\w-]+\.pdf$/', $file->filename);
        $url = $this->world->http->requests()[0]->url;
        self::assertStringContainsString('/api/track/label?id[]=' . self::TRACK . '&id[]=101123456790', $url);
    }

    public function testNotPdfRefused(): void
    {
        $this->answer(base64_encode('<html><script>alert(1)</script></html>'));

        $issue = $this->labels()->many([self::TRACK]);

        self::assertInstanceOf(LabelIssue::class, $issue);
        self::assertSame('sviat__rozetka__label_err_not_pdf', $issue->text);
    }

    public function testSvgRefused(): void
    {
        $this->answer(base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'));

        self::assertSame('sviat__rozetka__label_err_not_pdf', $this->labels()->many([self::TRACK])->text);
    }

    public function testBadBase64Refused(): void
    {
        $this->answer('%PDF-not*base64!');
        self::assertSame('sviat__rozetka__label_err_not_pdf', $this->labels()->many([self::TRACK])->text);

        $this->answer(['nested' => true]);
        self::assertSame('sviat__rozetka__label_err_not_pdf', $this->labels()->many([self::TRACK])->text);
    }

    public function testOver20MbRefused(): void
    {
        $this->answer(base64_encode('%PDF-' . str_repeat('x', LabelFile::MAX_BYTES)));

        self::assertSame('sviat__rozetka__label_err_not_pdf', $this->labels()->many([self::TRACK])->text);
    }

    public function testArrayGivesZip(): void
    {
        $this->answer([base64_encode(self::PDF), base64_encode(self::PDF . ' 2')]);

        $file = $this->labels()->many([self::TRACK, '101123456790']);

        self::assertSame('application/zip', $file->mime);
        self::assertStringStartsWith("PK\x03\x04", $file->bytes);

        $this->answer([base64_encode(self::PDF), base64_encode('<svg/>')]);
        self::assertInstanceOf(LabelIssue::class, $this->labels()->many([self::TRACK, '101123456790']), 'one bad file refuses all');
    }

    public function testCountLimits(): void
    {
        self::assertSame('sviat__rozetka__label_err_count', $this->labels()->many([])->text);
        $many = array_map(static fn(int $i): string => (string)(101123456700 + $i), range(1, 21));
        self::assertSame('sviat__rozetka__label_err_count', $this->labels()->many($many)->text);
        self::assertSame('sviat__rozetka__label_err_count', $this->labels()->many(['../x'])->text, 'only valid numbers go into the URL');
        self::assertSame([], $this->world->http->requests());
    }

    public function testContentNeverLogged(): void
    {
        $this->answer(base64_encode('<svg>secret-content</svg>'));
        $this->labels()->many([self::TRACK]);
        $this->answer(base64_encode(self::PDF));
        $this->labels()->many([self::TRACK]);

        $log = json_encode($this->world->logger->records);
        self::assertStringNotContainsString('secret-content', $log);
        self::assertStringNotContainsString(base64_encode('<svg>secret-content</svg>'), $log);
        self::assertStringNotContainsString('obj<<', $log);
        self::assertStringContainsString('svg', $log, 'only the kind and size of what came back');
    }
}
