<?php

namespace Modules\Sviat\RozetkaDelivery\Orders;

require_once __DIR__ . '/../Support/InMemoryParcelRows.php';

use Modules\Sviat\RozetkaDelivery\Support\InMemoryParcelRows;
use Okay\Core\BackendTranslations;
use Okay\Core\Modules\Module;
use Okay\Core\Modules\Modules;
use Okay\Modules\Sviat\DeliveryFilter\Helpers\DeliveryStatusGroups;
use Okay\Modules\Sviat\RozetkaDelivery\Extenders\DeliveryFilterExtender;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\DeliveryFilterHub;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelStatus;
use PHPUnit\Framework\TestCase;

/** ROZETKA у списку замовлень — лише через хаб Sviat/DeliveryFilter: група `rozetka` і пошук за номером ЕН. */
class DeliveryFilterHubTest extends TestCase
{
    private const string HUB = 'Okay\\Modules\\Sviat\\DeliveryFilter\\Helpers\\DeliveryFilterHelper';
    private const int MODULE = 42;

    private InMemoryParcelRows $rows;

    protected function setUp(): void
    {
        $this->rows = new InMemoryParcelRows();
        $this->rows->created(77, '101123456789');
        $this->rows->created(78, 'AAA-123456789');
    }

    private function hooks(string $label = 'ua', bool $active = true): DeliveryFilterExtender
    {
        $lang = [];
        include dirname(__DIR__, 5) . "/Okay/Modules/Sviat/RozetkaDelivery/Backend/lang/{$label}.php";
        $translations = $this->createStub(BackendTranslations::class);
        $translations->method('getTranslation')->willReturnCallback(static fn(string $key): string => $lang[$key] ?? '');
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(self::MODULE);
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(static fn($vendor, $name): bool => $active && $vendor === 'Sviat' && $name === 'RozetkaDelivery');

        return new DeliveryFilterExtender($this->rows, $translations, $module, $modules);
    }

    public function testGroupDescribesEveryFilterGroup(): void
    {
        $filters = $this->hooks()->deliveryStatusFilters(['np' => []]);

        self::assertSame(['np', 'rozetka'], array_keys($filters));
        self::assertSame('ROZETKA Delivery', $filters['rozetka']['title']);
        self::assertSame('Rozetka', $filters['rozetka']['short']);
        self::assertSame(ParcelStatus::FILTER_GROUPS, array_keys($filters['rozetka']['options']));
        self::assertSame('Без ЕН', $filters['rozetka']['options']['none']);
    }

    public function testLabelsPassHubWhitelist(): void
    {
        if (!class_exists(DeliveryStatusGroups::class)) {
            self::markTestSkipped('хаба Sviat/DeliveryFilter тут немає');
        }
        foreach (['ua', 'en', 'ru'] as $label) {
            $filters = $this->hooks($label)->deliveryStatusFilters([]);
            self::assertSame($filters, DeliveryStatusGroups::sanitize($filters), $label);
        }
    }

    public function testStatusBecomesEntityFilter(): void
    {
        self::assertSame(['status_id' => 2, 'rozetka_status_groups' => ['arrived']], $this->hooks()->filterByDeliveryStatus(['status_id' => 2], [], 'rozetka', 'arrived'));
        self::assertSame(['rozetka_without_parcel' => self::MODULE], $this->hooks()->filterByDeliveryStatus([], [], 'rozetka', 'none'));
        self::assertSame(['up_status_groups' => ['arrived']], $this->hooks()->filterByDeliveryStatus(['up_status_groups' => ['arrived']], [], 'up', 'arrived'));
    }

    public function testKeywordNormalized(): void
    {
        self::assertSame([3, 77], $this->hooks()->ordersByShipmentNumber([3], ' 101 123 456 789 '));
        self::assertSame([77], $this->hooks()->ordersByShipmentNumber([], '101-123-456-789'));
        self::assertSame([78], $this->hooks()->ordersByShipmentNumber([], 'aaa 123456789'));
        foreach (['Олена', '101123456780', '77', ''] as $keyword) {
            self::assertSame([5], $this->hooks()->ordersByShipmentNumber([5], $keyword), $keyword);
        }
    }

    public function testNoHubNoHooks(): void
    {
        self::assertSame([], DeliveryFilterHub::hooks(self::HUB . 'Missing'));
        self::assertSame([], DeliveryFilterHub::hooks(RozetkaHubWithoutSearch::class));
        self::assertSame(self::HUB, DeliveryFilterHub::HELPER);
        self::assertCount(3, DeliveryFilterHub::hooks(RozetkaHubWithAllMethods::class));
        self::assertStringContainsString('DeliveryFilterHub::hooks(DeliveryFilterHub::HELPER)', (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Init/Init.php'));
    }

    /** Адмінка запускає init() і вимкнених модулів: ланки вимкненого перевізника зареєстровані, але мусять мовчати. */
    public function testDisabledModulePassesThrough(): void
    {
        $hooks = $this->hooks('ua', false);

        self::assertSame(['xx' => []], $hooks->deliveryStatusFilters(['xx' => []]));
        self::assertSame(['page' => 1], $hooks->filterByDeliveryStatus(['page' => 1], ['page' => 1], 'rozetka', 'arrived'));
        self::assertSame([5], $hooks->ordersByShipmentNumber([5], '101123456789'));
    }
}

class RozetkaHubWithAllMethods
{
    public function findDeliveryStatusFilters(): array
    {
        return [];
    }

    public function filterByDeliveryStatus(array $filter, string $carrier, string $status): array
    {
        return $filter;
    }

    public function findOrderIdsByShipmentNumber(string $keyword): array
    {
        return [];
    }
}

class RozetkaHubWithoutSearch
{
    public function findDeliveryStatusFilters(): array
    {
        return [];
    }
}
