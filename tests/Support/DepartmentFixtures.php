<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

/** Сторінка `GET department` у формі живої відповіді; назви, адреси й UUID вигадані. */
final class DepartmentFixtures
{
    /** @return array<string, mixed> */
    public static function page(): array
    {
        return json_decode((string)file_get_contents(__DIR__ . '/fixtures/departments-page.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public static function point(int $index = 0, array $override = []): array
    {
        return array_replace(self::page()['data'][$index], $override);
    }
}
