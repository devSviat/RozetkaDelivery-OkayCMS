<?php

namespace Modules\Sviat\RozetkaDelivery\Init;

require_once __DIR__ . '/../Support/DepartmentFixtures.php';

use Modules\Sviat\RozetkaDelivery\Support\DepartmentFixtures;
use Okay\Core\Modules\EntityField;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\CityRow;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointRow;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\CityEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\GroupEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\OrderDeliveryEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\ParcelEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\ReceptionEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Entities\PointEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Init\Init;
use PHPUnit\Framework\TestCase;

class InitTablesTest extends TestCase
{
    public function testInstallCreatesModuleTables(): void
    {
        $tables = array_map(static fn(string $entity): string => $entity::getTable(), array_keys(Init::tables()));

        self::assertSame(['__sviat__rozetka__state', '__sviat__rozetka__cities', '__sviat__rozetka__points', '__sviat__rozetka__groups', '__sviat__rozetka__order_delivery', '__sviat__rozetka__parcels', '__sviat__rozetka__receptions'], $tables);
    }

    /** 1.0.0 — перша версія: оновлень, які Installer міг би запустити, немає. */
    public function testNoUpdateMethods(): void
    {
        $updates = array_filter(
            new \ReflectionClass(Init::class)->getMethods(),
            static fn(\ReflectionMethod $method): bool => str_starts_with($method->name, 'update_'),
        );

        self::assertSame([], array_values($updates));
        $manifest = json_decode((string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Init/module.json'), true);
        self::assertSame('1.0.2', $manifest['version']);
        self::assertSame('4.5.3', $manifest['Okay']);
    }

    /** Таблиці, що вже є (модуль видалили й ставлять знову), не створюються вдруге. */
    public function testOnlyMissingTablesAreCreated(): void
    {
        $tables = Init::tables();

        self::assertSame(array_keys($tables), array_keys(Init::missingTables($tables, [], 'ok_')));
        self::assertSame([], Init::missingTables($tables, ['ok_sviat__rozetka__state', 'ok_sviat__rozetka__cities', 'ok_sviat__rozetka__points', 'ok_sviat__rozetka__groups', 'ok_sviat__rozetka__order_delivery', 'ok_sviat__rozetka__parcels', 'ok_sviat__rozetka__receptions'], 'ok_'));
    }

    /** Масовий запис іде повз entity, тож кожен ключ рядка мусить бути колонкою, а кожна колонка — полем entity. */
    public function testPointColumnsMatchTheEntity(): void
    {
        $fields = Init::tables()[PointEntity::class];
        $columns = self::names($fields);

        self::assertSame(PointEntity::getFields(), $columns);
        $row = PointRow::fromApi(DepartmentFixtures::point(0), 1)?->columns();
        self::assertSame($columns, [...array_keys($row), 'seen_run', 'updated_at']);
        self::assertSame(['city_id'], self::indexed($fields, EntityField::INDEX));
        self::assertTrue(self::field($fields, 'extra_schedule')->isNullable());
        self::assertSame('decimal(10,2)', strtolower((string)self::field($fields, 'max_cost')->getType()));
    }

    /** Знімок пишеться через entity: кожне поле entity має колонку, а замовлення має щонайбільше один рядок. */
    public function testOrderDeliveryColumnsMatchTheEntity(): void
    {
        $fields = Init::tables()[OrderDeliveryEntity::class];

        self::assertSame(OrderDeliveryEntity::getFields(), self::names($fields));
        self::assertSame(['order_id'], self::indexed($fields, EntityField::INDEX_UNIQUE));
    }

    /** Одна ЕН на замовлення; номер і `visible_id` спроби унікальні, NULL резерву дублем не є. */
    public function testParcelColumnsMatchTheEntity(): void
    {
        $fields = Init::tables()[ParcelEntity::class];

        self::assertSame(ParcelEntity::getFields(), self::names($fields));
        self::assertSame(['order_id', 'track_id', 'visible_id'], self::indexed($fields, EntityField::INDEX_UNIQUE));
        self::assertSame(['reception_id'], self::indexed($fields, EntityField::INDEX));
        self::assertTrue(self::field($fields, 'track_id')->isNullable());
        self::assertTrue(self::field($fields, 'visible_id')->isNullable());
        self::assertSame('mediumtext', strtolower((string)self::field($fields, 'history')->getType()));
    }

    public function testReceptionColumnsMatchTheEntity(): void
    {
        $fields = Init::tables()[ReceptionEntity::class];

        self::assertSame(ReceptionEntity::getFields(), self::names($fields));
        self::assertSame(['reception_id'], self::indexed($fields, EntityField::INDEX_UNIQUE));
    }

    public function testCityColumnsMatchTheEntity(): void
    {
        $fields = Init::tables()[CityEntity::class];
        $columns = self::names($fields);

        self::assertSame(CityEntity::getFields(), $columns);
        $row = CityRow::fromPoint(DepartmentFixtures::point(0)['location'])?->columns();
        self::assertSame([], array_values(array_diff([...array_keys($row), 'points', 'seen_run', 'updated_at'], $columns)));
        self::assertSame(['search_text'], self::indexed($fields, EntityField::INDEX));
    }

    public function testGroupColumnsMatchTheEntity(): void
    {
        $fields = Init::tables()[GroupEntity::class];

        self::assertSame(GroupEntity::getFields(), array_values(array_diff(self::names($fields), ['name'])));
        self::assertSame(['name'], self::names(array_values(array_filter($fields, static fn(EntityField $field): bool => $field->isLangField()))));
    }

    /** @param list<EntityField> $fields */
    private static function indexed(array $fields, string $index): array
    {
        return self::names(array_values(array_filter($fields, static fn(EntityField $field): bool => isset($field->getIndexes()[$index]))));
    }

    /** @param list<EntityField> $fields */
    private static function field(array $fields, string $name): EntityField
    {
        foreach ($fields as $field) {
            if ($field->getName() === $name) {
                return $field;
            }
        }
        self::fail($name);
    }

    /** @param list<EntityField> $fields */
    public static function names(array $fields): array
    {
        return array_map(static fn(EntityField $field): string => $field->getName(), $fields);
    }
}
