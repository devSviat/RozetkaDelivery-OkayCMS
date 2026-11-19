<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Parcels;

use Okay\Modules\Sviat\RozetkaDelivery\Cart\PointFit;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointLimits;

/** ЕН замовлення, якою вона піде в ROZETKA Delivery: значення з замовлення й налаштувань, правлені менеджером у формі. */
final readonly class ParcelDraft
{
    public const array PAYERS = ['sender', 'receiver'];
    private const string P = 'sviat__rozetka__';

    public function __construct(
        public int $orderId,
        public string $lastName,
        public string $firstName,
        public string $middleName,
        public string $phone,
        /** Рядок PointEntity точки видачі: з нього `id`, `city_id` і ліміти. */
        public ?object $point,
        /** Рядок PointEntity точки прийому з налаштувань. */
        public ?object $senderPoint,
        public ParcelConfig $config,
        public float $weightKg,
        public int $lengthCm,
        public int $widthCm,
        public int $heightCm,
        public float $declaredValue,
        public string $payer,
        /** setting | in_total | free | manual */
        public string $payerReason,
        /** Спосіб оплати замовлення зараз — післяплата, замовлення не оплачене й є що стягнути. */
        public bool $codAllowed,
        public float $codAmount,
        public ?float $codFeeEstimate,
        public bool $orderPaid,
        /** Що лишилось стягнути з покупця після ланцюжка AmountDue, грн. */
        public float $due,
        public bool $releaseUnpaid,
        public bool $mass,
        public string $description,
        /** @var list<array{field: string, key: string}> помилки розбору форми */
        public array $formErrors = [],
    ) {
    }

    public function cod(): bool
    {
        return $this->codAmount > 0;
    }

    /**
     * Точка видасть товар безкоштовно, бо `cost = 0`, а гроші за замовлення ще не надійшли. Аванс, що покрив
     * усю суму (due = 0), — не цей випадок.
     */
    public function awaitsPayment(): bool
    {
        return !$this->orderPaid && $this->due > 0 && !$this->cod();
    }

    /** Найбільша оголошена, яку прийме точка видачі, грн. */
    public function costLimit(): float
    {
        return $this->limits()['max_cost'];
    }

    /** @return list<array{field: string, key: string}> */
    public function errors(): array
    {
        $errors = $this->formErrors;
        $add = static function (string $field, string $key) use (&$errors): void {
            $errors[] = ['field' => $field, 'key' => self::P . $key];
        };
        $limits = $this->limits();

        if ($this->lastName === '' || $this->firstName === '') {
            $add('last_name', 'parcel_err_name');
        }
        if (RozetkaPhone::normalize($this->phone) === null) {
            $add('phone', 'parcel_err_phone');
        }
        if ($this->point === null || (int)($this->point->can_give_out ?? 0) !== 1) {
            $add('point', 'parcel_err_point');
        }
        if (!is_finite($this->weightKg) || $this->weightKg <= 0 || $this->weightKg > $limits['max_weight'] + 1e-9) {
            $add('weight', 'parcel_err_weight');
        }
        $sides = [$this->lengthCm, $this->widthCm, $this->heightCm];
        if (min($sides) < 1 || max($sides) > $limits['max_length'] + 1e-9) {
            $add('sides', 'parcel_err_sides');
        } elseif ($this->lengthCm * $this->widthCm * $this->heightCm / PointFit::VOLUME_DIVISOR > $limits['max_volume_weight'] + 1e-9) {
            $add('sides', 'parcel_err_volume');
        }
        if (!is_finite($this->declaredValue) || $this->declaredValue <= 0) {
            $add('declared_value', 'parcel_err_declared');
        } elseif ($this->declaredValue > $limits['max_cost'] + 1e-9) {
            $add('declared_value', 'parcel_err_declared_limit');
        }
        if (!is_finite($this->codAmount) || $this->codAmount < 0) {
            $add('cod_amount', 'parcel_err_cod');
        } elseif ($this->cod() && $this->codAmount > $this->declaredValue + 1e-9) {
            $add('cod_amount', 'parcel_err_cod_over_declared');
        }
        if ($this->cod() && (int)($this->point->prepay_only ?? 0) === 1) {
            $add('cod_amount', 'parcel_err_cod_prepay_only');
        }
        if (!in_array($this->payer, self::PAYERS, true)) {
            $add('payer', 'parcel_err_payer');
        }
        if (trim($this->description) === '') {
            $add('description', 'parcel_err_description');
        }
        if (!$this->senderReady()) {
            $add('sender', 'parcel_err_sender');
        }
        // Масове створення не має форми, де менеджер підтвердив би оплату поза сайтом.
        if ($this->awaitsPayment() && (!$this->releaseUnpaid || $this->mass)) {
            $add('release_unpaid', 'parcel_err_unpaid');
        }

        return $errors;
    }

    /** Накладає форму менеджера; поля, яких у ній немає, лишаються як були. */
    public function withForm(array $post): self
    {
        $form = ParcelInput::fromPost($post);
        $formErrors = [];
        $number = static function (string $field, string $errorField, string $key, bool $whole = false) use ($form, &$formErrors): ?float {
            if (!array_key_exists($field, $form)) {
                return null;
            }
            // Дробова сторона мовчки округлилась би; текст замість числа — помилка поля, а не старе значення.
            if (!is_float($form[$field]) || ($whole && $form[$field] !== floor($form[$field]))) {
                $formErrors[] = ['field' => $errorField, 'key' => self::P . $key];

                return null;
            }

            return $form[$field];
        };
        $text = static fn(string $field, string $current): string => is_string($form[$field] ?? null) ? $form[$field] : $current;

        $weight = $number('weight', 'weight', 'parcel_err_weight');
        $length = $number('length', 'sides', 'parcel_err_sides', true);
        $width = $number('width', 'sides', 'parcel_err_sides', true);
        $height = $number('height', 'sides', 'parcel_err_sides', true);
        $declared = $number('declared_value', 'declared_value', 'parcel_err_declared');
        // Суму післяплати менеджер править лише там, де післяплата можлива: інакше точка видала б «з оплатою», якої ніхто не чекав.
        $cod = $this->codAllowed ? $number('cod_amount', 'cod_amount', 'parcel_err_cod') : null;
        $codAmount = $cod !== null ? round($cod, 2) : $this->codAmount;
        $payer = $text('payer', $this->payer);
        $phone = $text('phone', $this->phone);

        return new self(
            $this->orderId,
            $text('last_name', $this->lastName),
            $text('first_name', $this->firstName),
            $text('middle_name', $this->middleName),
            RozetkaPhone::normalize($phone) ?? $phone,
            $this->point,
            $this->senderPoint,
            $this->config,
            $weight !== null ? round($weight, 3) : $this->weightKg,
            $length !== null ? (int)$length : $this->lengthCm,
            $width !== null ? (int)$width : $this->widthCm,
            $height !== null ? (int)$height : $this->heightCm,
            $declared !== null ? round($declared, 2) : $this->declaredValue,
            $payer,
            $payer !== $this->payer ? 'manual' : $this->payerReason,
            $this->codAllowed,
            $codAmount,
            $codAmount > 0 ? $this->config->cod->estimateFee($codAmount) : null,
            $this->orderPaid,
            $this->due,
            !$this->mass && ($form['release_unpaid'] ?? false) === true,
            $this->mass,
            $text('description', $this->description),
            $formErrors,
        );
    }

    /** @return array<string, mixed> поле `data` тіла `POST track`, без `visible_id` (його дає сервіс на кожну спробу) */
    public function body(): array
    {
        $shipping = $this->config->shipping;

        return [
            'type' => 'dept-dept',
            'places' => 1,
            'delivery_payer' => $this->payer,
            'cost' => round($this->codAmount, 2),
            'insurance_cost' => round($this->declaredValue, 2),
            'params' => [
                'weight' => $this->weightKg,
                'length' => $this->lengthCm,
                'width' => $this->widthCm,
                'height' => $this->heightCm,
                'volume' => round($this->lengthCm * $this->widthCm * $this->heightCm / 1_000_000, 6),
            ],
            'description' => mb_substr(trim($this->description), 0, ParcelInput::DESCRIPTION_MAX),
            'sender' => self::party(
                (string)($this->senderPoint->city_id ?? $shipping->senderCityId),
                (string)($this->senderPoint->id ?? $shipping->senderPointId),
                $shipping->senderLastName,
                $shipping->senderFirstName,
                $shipping->senderMiddleName,
                $shipping->senderPhone,
            ),
            'recipient' => self::party(
                (string)($this->point->city_id ?? ''),
                (string)($this->point->id ?? ''),
                $this->lastName,
                $this->firstName,
                $this->middleName,
                $this->phone,
            ),
        ];
    }

    /** @return array{max_weight: float, max_volume_weight: float, max_length: float, max_cost: float} */
    private function limits(): array
    {
        $number = static fn(mixed $value): ?float => is_numeric($value) && (float)$value > 0 ? (float)$value : null;
        $point = $this->point;

        return new PointLimits(
            $number($point->max_weight ?? null),
            $number($point->max_volume_weight ?? null),
            $number($point->max_length ?? null),
            $number($point->max_cost ?? null),
            false,
        )->effective();
    }

    private function senderReady(): bool
    {
        $shipping = $this->config->shipping;

        return $shipping->senderLastName !== ''
            && $shipping->senderFirstName !== ''
            && RozetkaPhone::normalize($shipping->senderPhone) !== null
            && $this->senderPoint !== null
            && (int)($this->senderPoint->can_receive ?? 0) === 1;
    }

    /** @return array<string, string|list<string>> */
    private static function party(string $cityId, string $pointId, string $last, string $first, string $middle, string $phone): array
    {
        $party = [
            'city' => strtolower($cityId),
            'department' => strtolower($pointId),
            'last_name' => PersonName::clean($last),
            'first_name' => PersonName::clean($first),
        ];
        if (PersonName::clean($middle) !== '') {
            $party['middle_name'] = PersonName::clean($middle);
        }

        return $party + ['phone' => [RozetkaPhone::normalize($phone) ?? $phone]];
    }
}
