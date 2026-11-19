<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Quote;

/**
 * День відправки для орієнтовної дати: година приймання з API не видно (дата не залежить від години
 * `send_date`), тож після власної години відправки магазину посилка вважається завтрашньою.
 */
final class SendingDate
{
    /**
     * @param string $cutoff HH:MM за Києвом; посилка, оформлена о цій порі чи пізніше, їде завтра
     * @return string Y-m-d за Києвом
     */
    public static function for(int $now, string $cutoff): string
    {
        $at = new \DateTimeImmutable('@' . $now)->setTimezone(new \DateTimeZone('Europe/Kyiv'));
        $today = preg_match('/^(\d{2}):(\d{2})$/', $cutoff, $m) === 1 && $at < $at->setTime((int)$m[1], (int)$m[2]);

        return ($today ? $at : $at->modify('+1 day'))->format('Y-m-d');
    }

    /** `send_date` для API: день відправки о годині відправки, за Києвом (сервер порівнює з київським часом). */
    public static function sendAt(string $day, string $cutoff): string
    {
        return $day . ' ' . (ShippingConfig::cutoff($cutoff) ?? ShippingConfig::DEFAULT_CUTOFF) . ':00';
    }
}
