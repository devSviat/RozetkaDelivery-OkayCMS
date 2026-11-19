<?php

namespace Modules\Sviat\RozetkaDelivery\Orders;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';
require_once __DIR__ . '/../Parcels/LabelsTest.php';
require_once __DIR__ . '/../Support/InMemoryReceptions.php';
require_once __DIR__ . '/../Support/ArraySettings.php';
require_once __DIR__ . '/../Support/SettingsPage.php';

use Modules\Sviat\RozetkaDelivery\Parcels\LabelsTest;
use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryParcelRows;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryReceptions;
use Modules\Sviat\RozetkaDelivery\Support\SettingsPage;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionService;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrdersMassAction;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\LabelFile;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\Labels;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\MassParcels;
use PHPUnit\Framework\TestCase;

class OrdersMassActionTest extends TestCase
{
    private ApiWorld $world;
    private InMemoryParcelRows $rows;

    protected function setUp(): void
    {
        $this->world = new ApiWorld();
        $this->rows = new InMemoryParcelRows();
    }

    private function action(): OrdersMassAction
    {
        $labels = new Labels($this->world->client(), $this->world->logger, $this->world->clock());

        $receptions = new ReceptionService(
            $this->world->client(),
            $this->rows,
            new InMemoryReceptions(),
            new ArraySettings([ShippingConfig::SENDER_POINT_ID => 'aaaaaaaa-0000-4000-8000-000000000009']),
            new ParcelIssues(),
            $this->world->logger,
            $this->world->clock(),
        );

        return new OrdersMassAction(new \ReflectionClass(MassParcels::class)->newInstanceWithoutConstructor(), $labels, $this->rows, $this->world->logger, $receptions);
    }

    public function testForeignActionOrBadSessionIgnored(): void
    {
        $session = [];
        self::assertFalse($this->action()->handle(['action' => 'delete', 'check' => ['1']], true, $session));
        self::assertFalse($this->action()->handle(['action' => OrdersMassAction::LABELS, 'check' => ['1']], false, $session));
        self::assertSame([], $session);
        self::assertSame([], $this->world->http->requests());
    }

    public function testLabelsOneFileForParcelsOnly(): void
    {
        $this->rows->created(1, '101123456781');
        $this->rows->created(2, '101123456782');
        $this->rows->reserve(3, '2027-01-15 10:00:00');
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['label' => base64_encode(LabelsTest::PDF)]]);
        $session = [];

        $file = $this->action()->handle(['action' => OrdersMassAction::LABELS, 'check' => ['1', '2', '3', '4']], true, $session);

        self::assertInstanceOf(LabelFile::class, $file);
        self::assertSame('application/pdf', $file->mime);
        self::assertStringContainsString('id[]=101123456781&id[]=101123456782', $this->world->http->requests()[0]->url);
        $taken = OrdersMassAction::take($session);
        self::assertSame([3, 4], array_column($taken['report']->rows, 'orderId'), 'skipped orders wait for the list');
    }

    public function testNotPdfBecomesError(): void
    {
        $this->rows->created(1, '101123456781');
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['label' => base64_encode('<svg/>')]]);
        $session = [];

        self::assertTrue($this->action()->handle(['action' => OrdersMassAction::LABELS, 'check' => ['1']], true, $session));
        self::assertSame('sviat__rozetka__label_err_not_pdf', OrdersMassAction::take($session)['error']['key']);
    }

    public function testOverTwentyLeftUntouched(): void
    {
        $session = [];
        $this->action()->handle(['action' => OrdersMassAction::LABELS, 'check' => array_map(strval(...), range(1, 23))], true, $session);

        self::assertSame(3, OrdersMassAction::take($session)['report']->untouched);
        self::assertSame([], $this->world->http->requests());
    }

    /** Причина відмови Rozetka доходить до менеджера як текст: у звіті вона екранована. */
    public function testReceptionReportEscapesRejectedReason(): void
    {
        $this->rows->created(1, '101123456781');
        $this->rows->created(2, '101123456782');
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['reception_id' => 555, 'rejected_tracks' => [['track_id' => '101123456782', 'reason' => '<b onmouseover=alert(1)>Вже в реєстрі</b>']]]]);
        $session = [];

        self::assertTrue($this->action()->handle(['action' => OrdersMassAction::RECEPTION, 'check' => ['1', '2']], true, $session));
        $view = OrdersMassAction::view(OrdersMassAction::take($session), static fn(string $key): string => SettingsPage::lang('ua')[$key] ?? $key);
        $html = SettingsPage::render(['rozetka_mass_result' => $view], 'ua', 'mass_result.tpl');

        self::assertStringContainsString('555', (string)$view['title']);
        self::assertSame(2, $view['rows'][0]['orderId']);
        self::assertStringNotContainsString('<b onmouseover', $html);
        self::assertStringContainsString('&lt;b onmouseover=alert(1)&gt;', $html);
    }
}
