<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\KeyCheck;

/**
 * Те, що модуль знає про партнера з auth/verify. Телефон, email, контактна особа й merchant_id
 * у відповіді є, але сюди не потрапляють: стан модуля бачать і в дампі бази.
 */
final readonly class PartnerInfo
{
    public const array OK_STATUSES = ['active', 'verified'];
    public const int AUTOBLOCK_WARN_DAYS = 3;
    public const int CONTRACT_WARN_DAYS = 30;

    public function __construct(
        public ?int $id,
        public string $status,
        public string $name,
        public ?bool $isLegal,
        public ?bool $hasPrepayment,
        public ?bool $activeInsurance,
        public ?string $autoblockDate,
        public ?int $autoblockDays,
        public ?string $contractNumber,
        public ?string $contractSigned,
        public ?string $contractExpires,
        public ?bool $consolidatedBalance,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromVerify(array $data): self
    {
        $contract = is_array($data['contract'] ?? null) ? $data['contract'] : [];

        return new self(
            is_int($data['id'] ?? null) ? $data['id'] : (is_float($data['id'] ?? null) ? (int)$data['id'] : null),
            self::text($data['status'] ?? null),
            self::text($data['name'] ?? null),
            self::flag($data['is_legal'] ?? null),
            self::flag($data['has_prepayment'] ?? null),
            self::flag($data['is_active_insurance'] ?? null),
            self::nullableText($data['autoblock_date'] ?? null),
            is_int($data['autoblock_days'] ?? null) || is_float($data['autoblock_days'] ?? null) ? (int)$data['autoblock_days'] : null,
            self::nullableText($contract['number'] ?? null),
            self::nullableText($contract['sign_date'] ?? null),
            self::nullableText($contract['expiration_date'] ?? null),
            self::flag($data['is_consolidated_balance'] ?? null),
        );
    }

    /** @return list<string> мовні ключі банерів */
    public function warnings(int $now): array
    {
        $warnings = [];
        if (!in_array($this->status, self::OK_STATUSES, true)) {
            $warnings[] = 'sviat__rozetka__warn_partner_status';
        }
        if ($this->autoblockDays !== null && $this->autoblockDays <= self::AUTOBLOCK_WARN_DAYS) {
            $warnings[] = 'sviat__rozetka__warn_autoblock';
        }
        $expires = $this->contractExpiresAt();
        if ($expires !== null && $expires - $now <= self::CONTRACT_WARN_DAYS * 86400) {
            $warnings[] = 'sviat__rozetka__warn_contract_expires';
        }

        return $warnings;
    }

    /** Дати Rozetka без поясу — київський час. */
    public function contractExpiresAt(): ?int
    {
        if ($this->contractExpires === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($this->contractExpires, new \DateTimeZone('Europe/Kyiv'))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    public function encode(): string
    {
        return json_encode([
            'id' => $this->id,
            'status' => $this->status,
            'name' => $this->name,
            'is_legal' => $this->isLegal,
            'has_prepayment' => $this->hasPrepayment,
            'is_active_insurance' => $this->activeInsurance,
            'autoblock_date' => $this->autoblockDate,
            'autoblock_days' => $this->autoblockDays,
            'contract' => ['number' => $this->contractNumber, 'sign_date' => $this->contractSigned, 'expiration_date' => $this->contractExpires],
            'is_consolidated_balance' => $this->consolidatedBalance,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function decode(?string $json): ?self
    {
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data) || !is_string($data['status'] ?? null) || !is_string($data['name'] ?? null)) {
            return null;
        }

        return self::fromVerify($data);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === '' ? null : $text;
    }

    private static function flag(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
