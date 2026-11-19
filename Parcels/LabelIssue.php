<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

/**
 * Чому файла немає: мовний ключ `sviat__rozetka__…` або текст Rozetka як є (шаблон його екранує);
 * `advice` — мовний ключ поради, що робити далі.
 */
final readonly class LabelIssue
{
    public function __construct(public string $text, public ?string $advice = null)
    {
    }
}
