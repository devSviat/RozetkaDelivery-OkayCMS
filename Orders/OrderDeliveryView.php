<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Orders;

/** Збережений вибір замовлення як рядки тексту — для сторінок замовлення (покупця й адмінки) та обох листів замовлення. */
final class OrderDeliveryView
{
    /**
     * Читає лише знімок замовлення, ніколи довідник: назви мають лишитися тими, що обрав покупець.
     * Післяплата — з поточного способу оплати (`$codNow`): покупець міг змінити його після оформлення.
     * Оцінка комісії кошика показується, лише коли й кошик рахував післяплату.
     *
     * @param ?bool $codNow поточний спосіб оплати — післяплата; null — лише знімок
     * @return array{city:string, point:string, estimatedDate:?string, receiverPays:bool, cod:bool, codFee:?float}
     */
    public static function lines(object $row, ?bool $codNow = null): array
    {
        $date = (string)($row->estimated_date ?? '');
        $cartCod = (int)($row->cod ?? 0) === 1;
        $cod = $codNow ?? $cartCod;

        return [
            'city' => (string)($row->city_name ?? ''),
            'point' => (string)($row->point_title ?? ''),
            'estimatedDate' => preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1 ? substr($date, 0, 10) : null,
            'receiverPays' => ($row->price_source ?? null) === 'receiver',
            'cod' => $cod,
            'codFee' => $cod && $cartCod && is_numeric($row->cod_fee ?? null) ? (float)$row->cod_fee : null,
        ];
    }
}
