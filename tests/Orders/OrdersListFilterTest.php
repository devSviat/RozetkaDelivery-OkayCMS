<?php

namespace Modules\Sviat\RozetkaDelivery\Orders;

use Okay\Modules\Sviat\RozetkaDelivery\Orders\OrdersListFilter;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelStatus;
use PHPUnit\Framework\TestCase;

class OrdersListFilterTest extends TestCase
{
    private const int MODULE = 42;

    public function testTrackIsRecognised(): void
    {
        self::assertSame('101123456789', OrdersListFilter::track(' 101 123 456 789 '));
        self::assertSame('AAA-123456789', OrdersListFilter::track('aaa123456789'));
        // Ім'я, прізвище чи короткий номер — не номер ЕН: пошук у таблиці ЕН не запускається.
        foreach (['Олена', 'Shevchenko', '7112', "101123456789' OR 1=1", null, ['101123456789']] as $keyword) {
            self::assertNull(OrdersListFilter::track($keyword));
        }
    }

    public function testGroupFilter(): void
    {
        self::assertSame(['rozetka_status_groups' => ['in_transit']], OrdersListFilter::byStatus([], 'in_transit', self::MODULE));
        self::assertSame(['page' => 1], OrdersListFilter::byStatus(['page' => 1], null, self::MODULE));
        foreach (ParcelStatus::FILTER_GROUPS as $group) {
            self::assertNotSame([], OrdersListFilter::byStatus([], $group, self::MODULE), $group);
        }
    }

    public function testNoneFilter(): void
    {
        self::assertSame('none', OrdersListFilter::NONE);
        self::assertSame(['rozetka_without_parcel' => self::MODULE], OrdersListFilter::byStatus([], 'none', self::MODULE));
    }

    public function testGroupComesOnlyFromTheList(): void
    {
        self::assertSame('received', OrdersListFilter::group('received'));
        self::assertSame('none', OrdersListFilter::group('none'));
        foreach (['', 'RECEIVED', 'unsettled', "received' OR 1=1", ['received'], null, 3, 0] as $raw) {
            self::assertNull(OrdersListFilter::group($raw));
            self::assertSame([], OrdersListFilter::byStatus([], $raw, self::MODULE));
        }
    }
}
