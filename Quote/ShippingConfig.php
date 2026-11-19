<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Quote;

/** Загальні для магазину значення відправлення: з них заповнюється форма ЕН і рахується ціна в кошику. */
final readonly class ShippingConfig
{
    public const string SENDER_LAST_NAME = 'sviat__rozetka__sender_last_name';
    public const string SENDER_FIRST_NAME = 'sviat__rozetka__sender_first_name';
    public const string SENDER_MIDDLE_NAME = 'sviat__rozetka__sender_middle_name';
    public const string SENDER_PHONE = 'sviat__rozetka__sender_phone';
    public const string SENDER_POINT_ID = 'sviat__rozetka__sender_point_id';
    public const string SENDER_CITY_ID = 'sviat__rozetka__sender_city_id';
    public const string DEFAULT_WEIGHT = 'sviat__rozetka__default_weight';
    public const string BOX_LENGTH = 'sviat__rozetka__box_length';
    public const string BOX_WIDTH = 'sviat__rozetka__box_width';
    public const string BOX_HEIGHT = 'sviat__rozetka__box_height';
    public const string RECEIVER_PAY = 'sviat__rozetka__receiver_pay';
    public const string DECLARE_TOTAL = 'sviat__rozetka__declare_total';
    public const string DECLARED_VALUE = 'sviat__rozetka__declared_value';
    public const string DESCRIPTION = 'sviat__rozetka__description';
    public const string CUTOFF = 'sviat__rozetka__cutoff';
    public const string CART_PRICE = 'sviat__rozetka__cart_price';
    public const string RECEIVER_PRICE_NOTE = 'sviat__rozetka__receiver_price_note';

    public const float DEFAULT_WEIGHT_KG = 1.0;
    public const int DEFAULT_LENGTH_CM = 30;
    public const int DEFAULT_WIDTH_CM = 20;
    public const int DEFAULT_HEIGHT_CM = 15;
    public const string DEFAULT_DESCRIPTION = 'Замовлення №{order}';
    public const string DEFAULT_CUTOFF = '16:00';
    /** Тариф «Доставка відправлення» з договору, грн з ПДВ; API ціни не має. */
    public const float DEFAULT_CART_PRICE = 49.0;

    public function __construct(
        public string $senderLastName,
        public string $senderFirstName,
        public string $senderMiddleName,
        public string $senderPhone,
        public string $senderPointId,
        public string $senderCityId,
        public float $defaultWeightKg,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
        public bool $receiverPay,
        public bool $declareTotal,
        public float $declaredValue,
        public string $description,
        /** HH:MM за Києвом: після цієї години відправлення вважається завтрашнім. */
        public string $cutoff,
        public float $cartPrice,
        public bool $receiverPriceNote,
    ) {
    }

    /** @param callable(string): mixed $get Settings::get */
    public static function fromSettings(callable $get): self
    {
        $text = static fn(string $key): string => is_scalar($value = $get($key)) ? trim((string)$value) : '';
        // Не збережене налаштування — це типове значення, а не «ні».
        $flag = static fn(string $key, bool $default): bool => ($raw = $text($key)) === '' ? $default : (bool)(int)$raw;
        $weight = (float)$text(self::DEFAULT_WEIGHT);
        $description = $text(self::DESCRIPTION);
        $price = $text(self::CART_PRICE);

        return new self(
            $text(self::SENDER_LAST_NAME),
            $text(self::SENDER_FIRST_NAME),
            $text(self::SENDER_MIDDLE_NAME),
            $text(self::SENDER_PHONE),
            strtolower($text(self::SENDER_POINT_ID)),
            strtolower($text(self::SENDER_CITY_ID)),
            $weight > 0 ? $weight : self::DEFAULT_WEIGHT_KG,
            self::side($text(self::BOX_LENGTH), self::DEFAULT_LENGTH_CM),
            self::side($text(self::BOX_WIDTH), self::DEFAULT_WIDTH_CM),
            self::side($text(self::BOX_HEIGHT), self::DEFAULT_HEIGHT_CM),
            $flag(self::RECEIVER_PAY, true),
            $flag(self::DECLARE_TOTAL, true),
            max(0.0, (float)$text(self::DECLARED_VALUE)),
            $description === '' ? self::DEFAULT_DESCRIPTION : $description,
            self::cutoff($text(self::CUTOFF)) ?? self::DEFAULT_CUTOFF,
            is_numeric($price) && (float)$price >= 0 ? (float)$price : self::DEFAULT_CART_PRICE,
            $flag(self::RECEIVER_PRICE_NOTE, true),
        );
    }

    /** Година у вигляді HH:MM або null, якщо це не година доби. */
    public static function cutoff(string $value): ?string
    {
        return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $m) === 1 ? sprintf('%02d:%s', (int)$m[1], $m[2]) : null;
    }

    /** Об'єм коробки, м³: Д×Ш×В у сантиметрах ділиться на 10⁶. */
    public function volumeM3(): float
    {
        return $this->lengthCm * $this->widthCm * $this->heightCm / 1_000_000;
    }

    private static function side(string $value, int $default): int
    {
        $side = (int)ceil((float)$value);

        return $side > 0 ? $side : $default;
    }
}
