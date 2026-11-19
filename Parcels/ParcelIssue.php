<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/**
 * Що показати менеджеру біля форми чи картки ЕН.
 *
 * `text` — мовний ключ `sviat__rozetka__…` або текст Rozetka як є (шаблон його екранує); `field` — поле форми
 * (ParcelInput) для позначки або null; `advice` — мовний ключ поради.
 */
final readonly class ParcelIssue
{
    public function __construct(
        public ?string $field,
        public string $text,
        public ?string $advice = null,
    ) {
    }
}
