<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows;

final class RowHash
{
    /** Додає `row_hash`: тоді незмінений запис пропускається, а не перезаписується на кожному запуску. */
    public static function with(array $row): array
    {
        $row['row_hash'] = sha1(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

        return $row;
    }
}
