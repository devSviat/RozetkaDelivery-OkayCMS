<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;

/** Налаштування, з яких складається тіло `POST track`: відправник, коробка, оголошена й комісія післяплати. */
final readonly class ParcelConfig
{
    public function __construct(
        public ShippingConfig $shipping,
        public CodConfig $cod,
    ) {
    }

    /** @param callable(string): mixed $get Settings::get */
    public static function fromSettings(callable $get): self
    {
        return new self(ShippingConfig::fromSettings($get), CodConfig::fromSettings($get));
    }
}
