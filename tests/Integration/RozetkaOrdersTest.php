<?php

namespace Modules\Sviat\RozetkaDelivery\Integration;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Module;
use Okay\Core\Settings;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\ParcelEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Integration\RozetkaOrders;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryLines;
use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrderDeliveryStore;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;
use PHPUnit\Framework\TestCase;

/**
 * Фасад для Messaging, OrdersExport і Checkbox. Помилка тут — SMS з номером ЕН, якої немає, або чек
 * «при отриманні» за посилку, гроші за яку покупець не віддав.
 */
class RozetkaOrdersTest extends TestCase
{
    private static function row(array $over = []): object
    {
        return (object)($over + [
            'order_id' => 7, 'track_id' => '101000000007', 'state' => 'created', 'status_group' => 'in_transit',
            'partial' => 0, 'cod_amount' => null, 'has_paid' => null, 'event_at' => '2026-11-10 12:00:00',
            'arrived_at' => null, 'expiration_date' => null,
        ]);
    }

    public function testOnlyCreatedHaveNumbers(): void
    {
        self::assertNull(RozetkaOrders::parcel(self::row(['state' => 'pending', 'track_id' => null])));
        self::assertNull(RozetkaOrders::parcel(self::row(['track_id' => ''])));
        self::assertNull(RozetkaOrders::parcel(self::row(['track_id' => null])));
        self::assertSame('101000000007', RozetkaOrders::parcel(self::row())?->trackId);
    }

    /** Запит пішов, відповіді немає: номера ще ніхто не знає, навіть якщо рядок його має від прив'язки. */
    public function testUncertainExcluded(): void
    {
        self::assertNull(RozetkaOrders::parcel(self::row(['state' => 'uncertain'])));
        self::assertNull(RozetkaOrders::parcel(self::row(['state' => 'pending'])));
    }

    public function testCarriesGroupCodAndDates(): void
    {
        $parcel = RozetkaOrders::parcel(self::row([
            'status_group' => 'arrived', 'cod_amount' => '250.50', 'has_paid' => null, 'arrived_at' => '2026-11-11 09:00:00',
            'expiration_date' => '2026-11-18 00:00:00', 'partial' => '0',
        ]));

        self::assertSame(7, $parcel->orderId);
        self::assertSame('arrived', $parcel->group);
        self::assertTrue($parcel->cod);
        self::assertNull($parcel->hasPaid);
        self::assertFalse($parcel->partial);
        self::assertSame('2026-11-10 12:00:00', $parcel->eventAt);
        self::assertSame('2026-11-11 09:00:00', $parcel->arrivedAt);
        self::assertSame('2026-11-18 00:00:00', $parcel->expirationDate);

        $received = RozetkaOrders::parcel(self::row(['status_group' => 'received', 'cod_amount' => '0.00', 'has_paid' => '1', 'partial' => '1', 'arrived_at' => '2026-11-11 09:00:00', 'event_at' => '0000-00-00 00:00:00']));
        self::assertFalse($received->cod);
        self::assertTrue($received->hasPaid);
        self::assertTrue($received->partial);
        self::assertNull($received->arrivedAt, 'дата прибуття лише поки посилка в точці');
        self::assertNull($received->eventAt);
        self::assertNull(RozetkaOrders::parcel(self::row(['status_group' => null]))->group);
    }

    public function testUnfinishedFilterParams(): void
    {
        $captured = [];
        $factory = $this->parcelFactory(static function (array $filter) use (&$captured): array {
            $captured = $filter;

            return [self::row(), self::row(['order_id' => 8, 'state' => 'uncertain'])];
        });

        $parcels = $this->facade($factory)->unfinished();

        self::assertSame([7], array_keys($parcels));
        self::assertEqualsWithDelta(time() - 60 * 86400, strtotime($captured['unfinished']), 5);
    }

    public function testParcelsSkipEmptyIdsAndReadFacadeRows(): void
    {
        $calls = 0;
        $factory = $this->parcelFactory(static function (array $filter) use (&$calls): array {
            $calls++;

            return array_map(static fn(int $id): object => self::row(['order_id' => $id, 'track_id' => '10100000000' . $id]), $filter['order_id']);
        });
        $orders = $this->facade($factory);

        self::assertSame([], $orders->parcels([0, -1]));
        self::assertSame(0, $calls);
        self::assertSame([3, 5], array_keys($orders->parcels([3, '5', 3])));
    }

    /** Частково видана, неоплачена, без післяплати чи до `since` — гроші за неї не отримані повністю або не зараз. */
    public function testReceivedCodSinceFilters(): void
    {
        $sql = $this->filterSql('filter__received_cod_since', '2026-11-01 00:00:00');

        self::assertStringContainsString("rzprc.state = 'created'", $sql['where']);
        self::assertStringContainsString("rzprc.status_group = 'received'", $sql['where']);
        self::assertStringContainsString('rzprc.partial = 0', $sql['where']);
        self::assertStringContainsString('rzprc.cod_amount > 0', $sql['where']);
        self::assertStringContainsString('rzprc.has_paid = 1', $sql['where']);
        self::assertStringContainsString('rzprc.event_at >= :rozetka_received_since', $sql['where']);
        self::assertSame(['rozetka_received_since' => '2026-11-01 00:00:00'], $sql['bound']);
    }

    public function testReceivedCodSinceReturnsOrderIds(): void
    {
        $captured = [];
        $factory = $this->parcelFactory(static function (array $filter) use (&$captured): array {
            $captured = $filter;

            return [(object)['order_id' => '9'], (object)['order_id' => '4']];
        });

        $ids = $this->facade($factory)->receivedCodSince('2026-11-01 00:00:00');

        self::assertSame([9, 4], $ids);
        self::assertSame(['received_cod_since' => '2026-11-01 00:00:00'], $captured);
    }

    public function testWithTrackSqlShape(): void
    {
        self::assertSame(
            "SELECT order_id FROM __sviat__rozetka__parcels WHERE state = 'created' AND track_id IS NOT NULL AND track_id <> ''",
            RozetkaOrders::withTrackSql(),
        );
    }

    public function testAddressCityIsFirstSegment(): void
    {
        $address = RozetkaOrders::address((object)['city_name' => 'м. Вигадане, Тестовий р-н, Тестова обл.', 'point_title' => 'Точка видачі 1', 'point_number' => '12']);

        self::assertSame('м. Вигадане', $address->city);
        self::assertSame('Точка видачі 1', $address->line);
        self::assertSame('12', $address->number);
        self::assertNull(RozetkaOrders::address(null));
    }

    public function testAddressNullForForeignDelivery(): void
    {
        self::assertNull($this->addressFacade(own: false)->addressOf(7));
        self::assertSame('м. Тест', $this->addressFacade(own: true)->addressOf(7)?->city);
    }

    /** Справжні OrderDeliveryLines і OrderDeliveryStore: доставка «своя», коли її module_id — id цього модуля. */
    private function addressFacade(bool $own): RozetkaOrders
    {
        $orders = $this->createStub(OrdersEntity::class);
        $orders->method('cols')->willReturnSelf();
        $orders->method('get')->willReturn((object)['id' => 7, 'delivery_id' => 5]);
        $deliveries = $this->createStub(DeliveriesEntity::class);
        $deliveries->method('get')->willReturn((object)['id' => 5, 'module_id' => $own ? 40 : 41]);
        $snapshots = $this->createStub(OrderDeliveryEntity::class);
        $snapshots->method('findOne')->willReturn((object)['city_name' => 'м. Тест, р-н, обл.', 'point_title' => 'Т1', 'point_number' => '5']);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static fn(string $class) => match ($class) {
            OrdersEntity::class => $orders,
            DeliveriesEntity::class => $deliveries,
            OrderDeliveryEntity::class => $snapshots,
        });
        $module = $this->createStub(Module::class);
        $module->method('getModuleIdByNamespace')->willReturn(40);
        $store = new OrderDeliveryStore($factory);

        return new RozetkaOrders($factory, new OrderDeliveryLines($factory, $module, $store, new CodPayments($factory, new CodSettings($this->createStub(Settings::class)))), $store);
    }

    private function facade(EntityFactory $factory): RozetkaOrders
    {
        $store = new OrderDeliveryStore($factory);

        return new RozetkaOrders($factory, new OrderDeliveryLines($factory, $this->createStub(Module::class), $store, new CodPayments($factory, new CodSettings($this->createStub(Settings::class)))), $store);
    }

    private function parcelFactory(\Closure $find): EntityFactory
    {
        $parcels = $this->createStub(ParcelEntity::class);
        $parcels->method('noLimit')->willReturnSelf();
        $parcels->method('cols')->willReturnSelf();
        $parcels->method('find')->willReturnCallback($find);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($parcels);

        return $factory;
    }

    /** @return array{where: string, bound: array<string, mixed>} */
    private function filterSql(string $method, mixed $value): array
    {
        $recorder = new class {
            public array $where = [];
            public array $bound = [];

            public function where(string $sql): static
            {
                $this->where[] = $sql;

                return $this;
            }

            public function bindValue(string $name, mixed $value): static
            {
                $this->bound[$name] = $value;

                return $this;
            }
        };
        $entity = new \ReflectionClass(ParcelEntity::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(ParcelEntity::class, 'select')->setValue($entity, $recorder);
        new \ReflectionMethod(ParcelEntity::class, $method)->invoke($entity, $value);

        return ['where' => implode(' AND ', $recorder->where), 'bound' => $recorder->bound];
    }
}
