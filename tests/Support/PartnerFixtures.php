<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

/** Відповідь auth/verify у формі схеми; усі значення вигадані. */
final class PartnerFixtures
{
    public const string EMAIL = 'owner@example.test';
    public const string PHONE = '380501112233';
    public const string CONTACT = 'Петренко Оксана';
    public const string MERCHANT = '0f0e0d0c-0000-4000-8000-000000000042';

    /** @return array<string, mixed> */
    public static function verify(array $override = []): array
    {
        return $override + [
            'statusCode' => 0,
            'id' => 4321,
            'merchant_id' => self::MERCHANT,
            'status' => 'active',
            'phone' => self::PHONE,
            'email' => self::EMAIL,
            'name' => 'ФОП Тестовий Продавець',
            'contact_person' => self::CONTACT,
            'is_aggree_public_data' => true,
            'is_aggree_public_offer' => true,
            'created_at' => '2026-01-10 10:00:00',
            'has_prepayment' => true,
            'autoblock_date' => '2027-01-20',
            'autoblock_days' => 40,
            'is_legal' => false,
            'receive_push_notifications' => true,
            'is_active_insurance' => false,
            'contract' => ['sign_date' => '2026-01-10 10:00:00', 'expiration_date' => '2027-12-31 23:59:59', 'number' => 'RD-0001'],
            'is_consolidated_balance' => false,
        ];
    }
}
