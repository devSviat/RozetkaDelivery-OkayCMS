<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/** Номер ЕН, як його вводить менеджер: 12 цифр або три літери й 9 цифр (формат у реєстрах). */
final class TrackNumber
{
    public static function normalize(string $raw): ?string
    {
        $compact = strtoupper((string)preg_replace('/[\s\-]+/u', '', $raw));
        if (preg_match('/^\d{12}$/', $compact) === 1) {
            return $compact;
        }

        return preg_match('/^([A-Z]{3})(\d{9})$/', $compact, $m) === 1 ? $m[1] . '-' . $m[2] : null;
    }
}
