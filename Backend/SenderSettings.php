<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\RozetkaPhone;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;

/** Блок «Відправник»: контактна особа (API вимагає прізвище й ім'я окремо), телефон і точка прийому. */
final class SenderSettings
{
    public const int NAME_MAX = 64;

    public function __construct(
        private readonly Settings $settings,
        private readonly SenderPoints $points,
    ) {
    }

    /** @return array{last_name:string, first_name:string, middle_name:string, phone:string, point_id:string, city_id:string} */
    public function read(): array
    {
        $config = ShippingConfig::fromSettings($this->settings->get(...));

        return [
            'last_name' => $config->senderLastName,
            'first_name' => $config->senderFirstName,
            'middle_name' => $config->senderMiddleName,
            'phone' => $config->senderPhone,
            'point_id' => $config->senderPointId,
            'city_id' => $config->senderCityId,
        ];
    }

    /**
     * Надіслане, у формі read(): форму з помилками показуємо з тим, що менеджер ввів.
     *
     * @param array<string, mixed> $post
     * @return array{last_name:string, first_name:string, middle_name:string, phone:string, point_id:string, city_id:string}
     */
    public static function fromPost(array $post): array
    {
        return [
            'last_name' => self::text($post, 'sender_last_name'),
            'first_name' => self::text($post, 'sender_first_name'),
            'middle_name' => self::text($post, 'sender_middle_name'),
            'phone' => self::text($post, 'sender_phone'),
            'point_id' => strtolower(self::text($post, 'sender_point_id')),
            'city_id' => strtolower(self::text($post, 'sender_city_id')),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string> поле форми → мовний ключ помилки
     */
    public function validate(array $post): array
    {
        $values = self::fromPost($post);
        $errors = [];
        foreach (['last_name' => true, 'first_name' => true, 'middle_name' => false] as $field => $required) {
            $length = mb_strlen($values[$field]);
            if (($required && $length < 1) || $length > self::NAME_MAX) {
                $errors["sender_{$field}"] = "sviat__rozetka__sender_err_{$field}";
            }
        }
        if (RozetkaPhone::normalize($values['phone']) === null) {
            $errors['sender_phone'] = 'sviat__rozetka__sender_err_phone';
        }
        if ($this->points->receivingCity($values['point_id']) === null) {
            $errors['sender_point_id'] = 'sviat__rozetka__sender_err_point';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string> помилки з validate(); якщо вони є, нічого не зберігається
     */
    public function save(array $post): array
    {
        $errors = $this->validate($post);
        if ($errors !== []) {
            return $errors;
        }
        $values = self::fromPost($post);
        $this->settings->set(ShippingConfig::SENDER_LAST_NAME, $values['last_name']);
        $this->settings->set(ShippingConfig::SENDER_FIRST_NAME, $values['first_name']);
        $this->settings->set(ShippingConfig::SENDER_MIDDLE_NAME, $values['middle_name']);
        $this->settings->set(ShippingConfig::SENDER_PHONE, RozetkaPhone::normalize($values['phone']));
        $this->settings->set(ShippingConfig::SENDER_POINT_ID, $values['point_id']);
        $this->settings->set(ShippingConfig::SENDER_CITY_ID, $this->points->receivingCity($values['point_id']));

        return [];
    }

    /** @param array<string, mixed> $post */
    private static function text(array $post, string $field): string
    {
        return is_string($post[$field] ?? null) ? trim(preg_replace('/\s+/u', ' ', $post[$field]) ?? '') : '';
    }
}
