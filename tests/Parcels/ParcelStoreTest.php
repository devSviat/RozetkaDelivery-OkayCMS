<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\ParcelEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelStore;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\PendingParcel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ParcelStoreTest extends TestCase
{
    private const string NOW = '2026-11-17 10:00:00';

    private function store(ParcelEntity $entity): ParcelStore
    {
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        return new ParcelStore($factory);
    }

    private function entity(): ParcelEntity&MockObject
    {
        return $this->createMock(ParcelEntity::class);
    }

    public function testReserveNumbersTheAttemptById(): void
    {
        $entity = $this->entity();
        $entity->method('add')->willReturn(7);
        $entity->method('findOne')->willReturn(
            (object)['id' => '7', 'order_id' => '1042', 'visible_id' => null, 'state' => PendingParcel::PENDING],
            (object)['id' => '7', 'order_id' => '1042', 'visible_id' => '1042-7', 'state' => PendingParcel::PENDING],
        );
        $entity->expects(self::once())->method('update')->with(7, ['visible_id' => '1042-7']);
        $entity->expects(self::never())->method('delete');

        self::assertSame('1042-7', $this->store($entity)->reserve(1042, self::NOW));
    }

    public function testDuplicateReservationIsRefused(): void
    {
        $entity = $this->entity();
        $entity->method('add')->willReturn(false);
        $entity->expects(self::never())->method('update');

        self::assertNull($this->store($entity)->reserve(1042, self::NOW));
    }

    /**
     * Невдала вставка на стоковому ядрі може віддати id попередньої вставки: рядок іншого запиту, що ще не встиг
     * записати номер, не стає резервом цього.
     */
    public function testStaleInsertIdIsNotAReservation(): void
    {
        $entity = $this->entity();
        $entity->method('add')->willReturn(3);
        $entity->method('findOne')->willReturn((object)['id' => '5', 'order_id' => '1042', 'visible_id' => null, 'state' => PendingParcel::PENDING]);
        $entity->expects(self::never())->method('update');

        self::assertNull($this->store($entity)->reserve(1042, self::NOW));
    }

    /** Сторінка списку замовлень не тягне JSON знімка й історії. */
    public function testOrdersListReadsOnlyTheColumnsOfTheRow(): void
    {
        $entity = $this->entity();
        $entity->expects(self::once())->method('cols')->with(ParcelStore::LIST_COLUMNS)->willReturnSelf();
        $entity->method('find')->willReturn([(object)['order_id' => '7', 'track_id' => '101123456789']]);

        self::assertSame([7], array_keys($this->store($entity)->forOrders([7, 0])));
        self::assertNotContains('details', ParcelStore::LIST_COLUMNS);
        self::assertNotContains('history', ParcelStore::LIST_COLUMNS);
    }

    /** Дубль номера update() ковтає: успіх видно лише з перечитаного рядка. */
    public function testCompleteChecksTheSavedRow(): void
    {
        $pending = (object)['id' => '5', 'order_id' => '1042', 'state' => PendingParcel::PENDING, 'track_id' => null];
        $entity = $this->entity();
        $entity->method('findOne')->willReturn($pending, $pending);
        $entity->expects(self::once())->method('update');

        self::assertFalse($this->store($entity)->complete(1042, ['track_id' => '101123456789']));
    }

    /** UPDATE номера ядро теж ковтає: без номера в перечитаному рядку резерв знімається, і запит до Rozetka не йде. */
    public function testUnsavedNumberIsNotAReservation(): void
    {
        $row = (object)['id' => '7', 'order_id' => '1042', 'visible_id' => null, 'state' => PendingParcel::PENDING];
        $entity = $this->entity();
        $entity->method('add')->willReturn(7);
        $entity->method('findOne')->willReturn($row, $row, $row);
        $entity->expects(self::once())->method('update')->with(7, ['visible_id' => '1042-7']);
        $entity->expects(self::once())->method('delete')->with([7]);

        self::assertNull($this->store($entity)->reserve(1042, self::NOW));
    }

    private const string TRACK = '101123456789';

    /** @return array<string, mixed> */
    private static function attached(): array
    {
        return ['track_id' => self::TRACK, 'created_at' => self::NOW, 'checked_at' => self::NOW];
    }

    public function testAttachNewRowIsConfirmedByReread(): void
    {
        $entity = $this->entity();
        $entity->method('findOne')->willReturn(false, (object)['id' => '9', 'order_id' => '1042', 'state' => PendingParcel::CREATED, 'track_id' => self::TRACK]);
        $entity->expects(self::once())->method('add')->with(self::callback(
            static fn(array $values): bool => $values['created_at'] === self::NOW && $values['order_id'] === 1042,
        ))->willReturn(9);

        self::assertTrue($this->store($entity)->attach(1042, self::attached()));
    }

    /** Дубль номера на стоковому ядрі може повернути id попередньої вставки: true без запису брехав би менеджеру. */
    public function testAttachStaleInsertIdIsNotSaved(): void
    {
        $entity = $this->entity();
        $entity->method('findOne')->willReturn(false, false);
        $entity->expects(self::once())->method('add')->willReturn(3);

        self::assertFalse($this->store($entity)->attach(1042, self::attached()));
    }

    /** Сутність з однією колонкою в cols() віддає значення, а не рядки: так відповідає справжня база. */
    public function testSetReceptionUpdatesFoundIds(): void
    {
        $entity = $this->createStub(ParcelEntity::class);
        $entity->method('cols')->willReturnSelf();
        $entity->method('find')->willReturn(['4', '9']);
        $updated = [];
        $entity->method('update')->willReturnCallback(static function (int $id, array $values) use (&$updated): bool {
            $updated[$id] = $values;

            return true;
        });

        $this->store($entity)->setReception(['101123456789', '101123456790'], 55);

        self::assertSame([4 => ['reception_id' => 55], 9 => ['reception_id' => 55]], $updated);
    }

    public function testClearReceptionUpdatesFoundIds(): void
    {
        $entity = $this->entity();
        $entity->method('cols')->willReturnSelf();
        $entity->method('noLimit')->willReturnSelf();
        $entity->method('find')->willReturn(['4']);
        $entity->expects(self::once())->method('update')->with(4, ['reception_id' => null]);

        $this->store($entity)->clearReception(55);
    }

    /** Чек «при отриманні» чекає саме на ці ЕН: частково видані й без післяплати сюди не входять. */
    public function testUnpaidCodFilter(): void
    {
        $recorder = new class {
            /** @var list<string> */
            public array $where = [];
            /** @var array<string, mixed> */
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
        new \ReflectionMethod(ParcelEntity::class, 'filter__unpaid_cod')->invoke($entity, ['event_since' => '2026-11-03 10:00:00', 'checked_before' => '2026-11-17 09:00:00']);
        $where = implode(' AND ', $recorder->where);

        self::assertStringContainsString("rzprc.state = 'created'", $where);
        self::assertStringContainsString("rzprc.status_group = 'received'", $where);
        self::assertStringContainsString('rzprc.partial = 0', $where);
        self::assertStringContainsString('rzprc.cod_amount > 0', $where);
        self::assertStringContainsString('(rzprc.has_paid IS NULL OR rzprc.has_paid <> 1)', $where);
        self::assertStringContainsString('rzprc.event_at >= :rozetka_unpaid_event_since', $where);
        self::assertStringContainsString('(rzprc.checked_at IS NULL OR rzprc.checked_at <= :rozetka_unpaid_checked_before)', $where);
        self::assertSame(['rozetka_unpaid_event_since' => '2026-11-03 10:00:00', 'rozetka_unpaid_checked_before' => '2026-11-17 09:00:00'], $recorder->bound);
    }
}
