<?php

namespace Modules\Sviat\RozetkaDelivery\Groups;

use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointKind;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\DefaultGroups;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsInput;
use Okay\Modules\Sviat\RozetkaDelivery\Groups\GroupsView;
use PHPUnit\Framework\TestCase;

class GroupsStoreTest extends TestCase
{
    public function testDefaultGroups(): void
    {
        $rows = DefaultGroups::rows();

        self::assertSame([
            [PointKind::fromTypeId(2)->value, PointKind::fromTypeId(7)->value],
            [PointKind::fromTypeId(10)->value],
            [PointKind::fromTypeId(14)->value],
        ], array_column($rows, 'kinds'));
        self::assertSame('Партнерські відділення (лише передоплата)', DefaultGroups::name($rows[2], 'ua'));
        self::assertSame('Точки видачі й магазини', DefaultGroups::name($rows[0], 'xx'));
        foreach ($rows as $row) {
            self::assertSame(['ua', 'en', 'ru'], array_keys($row['names']));
        }
    }

    public function testViewWarnsAboutKindsWithoutGroup(): void
    {
        $groups = [];
        foreach (DefaultGroups::rows() as $i => $row) {
            $groups[] = (object)['id' => $i + 1, 'name' => DefaultGroups::name($row, 'ua'), 'enabled' => $row['enabled'], 'position' => $row['position'], 'kinds' => json_encode($row['kinds'])];
        }

        $kinds = array_column(GroupsView::vars($groups, ['pickup' => 900])['kinds'], 'warning', 'key');

        self::assertSame(['pickup' => null, 'store' => null, 'postomat' => null, 'partner' => null, 'other' => 'no_group'], $kinds);
    }

    public function testInputNeedsAGroupForEveryKind(): void
    {
        $post = ['groups' => [['id' => '1', 'name' => 'Точки', 'enabled' => '1']], 'kinds' => ['pickup' => '1', 'store' => '1', 'postomat' => '1', 'partner' => '1', 'other' => '0']];

        $parsed = GroupsInput::parse($post);

        self::assertSame([], $parsed['errors']);
        self::assertSame(['pickup', 'store', 'postomat', 'partner'], $parsed['groups'][0]['kinds']);
    }
}
