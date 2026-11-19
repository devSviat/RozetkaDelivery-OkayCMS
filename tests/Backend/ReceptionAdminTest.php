<?php

namespace Modules\Sviat\RozetkaDelivery\Backend;

require_once __DIR__ . '/../Support/AdminHarness.php';
require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/ArraySettings.php';
require_once __DIR__ . '/../Support/InMemoryParcelRows.php';
require_once __DIR__ . '/../Support/InMemoryReceptions.php';
require_once __DIR__ . '/../Support/RecordingInit.php';

use Modules\Sviat\RozetkaDelivery\Support\AdminHarness;
use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryParcelRows;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryReceptions;
use Modules\Sviat\RozetkaDelivery\Support\RecordingInit;
use Okay\Core\Modules\Modules;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\Controllers\ReceptionAdmin;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelIssues;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionPrint;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionRows;
use Okay\Modules\Sviat\RozetkaDelivery\Receptions\ReceptionService;
use PHPUnit\Framework\TestCase;

class ReceptionAdminTest extends TestCase
{
    private ApiWorld $world;
    private InMemoryReceptions $receptions;
    private AdminHarness $page;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->world = new ApiWorld();
        $this->receptions = new InMemoryReceptions();
        $this->receptions->save(555, ['status' => 'new', 'tracks_amount' => 2, 'created_at' => date('Y-m-d H:i:s')]);
        $this->page = new AdminHarness();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        session_id('');
    }

    private function visit(string $method, array $post): void
    {
        $client = $this->world->client();
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturn(true);
        $this->page->visit(ReceptionAdmin::class, [
            ReceptionRows::class => $this->receptions,
            ReceptionService::class => new ReceptionService($client, new InMemoryParcelRows(), $this->receptions, new ArraySettings(), new ParcelIssues(), $this->world->logger, $this->world->clock()),
            ReceptionPrint::class => new ReceptionPrint($client, $this->world->logger),
            Modules::class => $modules,
        ], $method, $post);
    }

    public function testPostCsrfRights(): void
    {
        foreach (['print', 'refresh', 'delete', 'create_ready'] as $action) {
            $this->visit('GET', ['action' => $action, 'reception_id' => '555']);
            self::assertNull($this->page->status, "{$action}: GET only shows the page");

            $this->visit('POST', ['action' => $action, 'reception_id' => '555']);
            self::assertSame(403, $this->page->status, "{$action}: no token");
        }
        self::assertSame([], $this->world->http->requests());
        self::assertSame('new', $this->receptions->get(555)->status);

        $calls = RecordingInit::record();
        self::assertContains(['permission', 'ReceptionAdmin', 'orders'], $calls);
        self::assertContains(['menu', 'left_orders', ['sviat__rozetka__left_receptions' => ['ReceptionAdmin']]], $calls);
    }

    public function testPageListsReceptions(): void
    {
        $this->visit('GET', []);

        self::assertStringContainsString('№ 555', $this->page->content);
        self::assertStringContainsString('name="action" value="delete"', $this->page->content);
    }

    /** Дії — лише над реєстрами, сформованими з магазину: реєстр із кабінету модуль не чіпає. */
    public function testUnknownReceptionRefused(): void
    {
        $_SESSION['id'] = str_repeat('c', 64);
        session_id($_SESSION['id']);
        foreach (['print', 'refresh', 'delete'] as $action) {
            $this->visit('POST', ['action' => $action, 'reception_id' => '999', 'session_id' => $_SESSION['id']]);
        }

        self::assertSame([], $this->world->http->requests());
        self::assertNull($this->receptions->get(999));
    }
}
