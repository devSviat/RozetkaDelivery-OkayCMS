<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/** Телефон у вигляді `380XXXXXXXXX`, як його приймає ROZETKA Delivery для відправника й одержувача. */
final class RozetkaPhone
{
    /** Коди операторів мобільного зв'язку України: на міський номер не прийде SMS про посилку. */
    public const array MOBILE_CODES = ['39', '50', '63', '66', '67', '68', '73', '75', '77', '89', '91', '92', '93', '94', '95', '96', '97', '98', '99'];

    /** Приймає `+380…`, `380…` і `0XX…` з пробілами, дужками й дефісами; міські номери, літери й інше дають null. */
    public static function normalize(string $raw): ?string
    {
        if (preg_match('/^\+?[\d\s()\-]+$/', trim($raw)) !== 1) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '38' . $digits;
        }
        if (preg_match('/^380(\d{2})\d{7}$/', $digits, $m) !== 1) {
            return null;
        }

        return in_array($m[1], self::MOBILE_CODES, true) ? $digits : null;
    }
}
