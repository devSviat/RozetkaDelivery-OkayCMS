<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Groups;

use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointKind;

/** Групи, з якими стартує нове встановлення. Невідомий тип (`other`) групи не має, доки менеджер не вирішить. */
final class DefaultGroups
{
    /** @return list<array{position:int, enabled:int, kinds:list<string>, names:array{ua:string, en:string, ru:string}}> */
    public static function rows(): array
    {
        return [
            [
                'position' => 1,
                'enabled' => 1,
                'kinds' => [PointKind::Pickup->value, PointKind::Store->value],
                'names' => ['ua' => 'Точки видачі й магазини', 'en' => 'Pickup points and stores', 'ru' => 'Пункты выдачи и магазины'],
            ],
            [
                'position' => 2,
                'enabled' => 1,
                'kinds' => [PointKind::Postomat->value],
                'names' => ['ua' => 'Поштомати', 'en' => 'Parcel lockers', 'ru' => 'Почтоматы'],
            ],
            [
                'position' => 3,
                'enabled' => 1,
                'kinds' => [PointKind::Partner->value],
                'names' => ['ua' => 'Партнерські відділення (лише передоплата)', 'en' => 'Partner branches (prepaid only)', 'ru' => 'Партнёрские отделения (только предоплата)'],
            ],
        ];
    }

    /** Назва мовою сайту; для іншої мови — українська, менеджер її перейменує. */
    public static function name(array $row, string $languageLabel): string
    {
        return $row['names'][$languageLabel] ?? $row['names']['ua'];
    }
}
