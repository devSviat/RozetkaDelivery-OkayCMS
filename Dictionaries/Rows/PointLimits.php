<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows;

/**
 * Ліміти точки з `limitations`. Схема каже «грами» й «міліметри», але живі значення (cost 60000,
 * weight 10/30/150) — кілограми й гривні; length читається як сантиметри. Порожнє, 0 чи не число —
 * без ліміту.
 */
final readonly class PointLimits
{
    public const float NETWORK_LENGTH = 120;
    public const float NETWORK_WEIGHT = 15;
    public const float NETWORK_VOLUME_WEIGHT = 30;
    public const float NETWORK_COST = 30000;
    /** Вага понад тонну в точці видачі — це грами, як обіцяє схема, а не кілограми живих відповідей. */
    private const float GRAMS_ABOVE = 1000;

    public function __construct(
        public ?float $maxWeight,
        public ?float $maxVolumeWeight,
        public ?float $maxLength,
        public ?float $maxCost,
        public bool $suspectUnits,
    ) {
    }

    public static function fromApi(?array $limitations): self
    {
        $limitations ??= [];
        $weight = self::positive($limitations['weight'] ?? null);
        // Лише фактична вага: об'ємна 9000 трапляється в живих точках поряд з вагою 150, тож грамами вона не є.
        $suspect = $weight !== null && $weight > self::GRAMS_ABOVE;

        return new self(
            $suspect ? round($weight / 1000, 3) : $weight,
            self::positive($limitations['volumeWeight'] ?? null),
            self::positive($limitations['length'] ?? null),
            self::positive($limitations['cost'] ?? null),
            $suspect,
        );
    }

    /** @return array{max_weight: float, max_volume_weight: float, max_length: float, max_cost: float} ліміт точки, обмежений мережевим */
    public function effective(): array
    {
        return [
            'max_weight' => min($this->maxWeight ?? self::NETWORK_WEIGHT, self::NETWORK_WEIGHT),
            'max_volume_weight' => min($this->maxVolumeWeight ?? self::NETWORK_VOLUME_WEIGHT, self::NETWORK_VOLUME_WEIGHT),
            'max_length' => min($this->maxLength ?? self::NETWORK_LENGTH, self::NETWORK_LENGTH),
            'max_cost' => min($this->maxCost ?? self::NETWORK_COST, self::NETWORK_COST),
        ];
    }

    /** Поза діапазоном decimal(10,3) — сміття, а не відхилений через нього весь пакет. */
    private static function positive(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float)$value;

        return $number > 0 && $number < 9999999 ? $number : null;
    }
}
