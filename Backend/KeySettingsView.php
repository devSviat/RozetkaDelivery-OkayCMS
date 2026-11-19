<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Backend;

use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\CredentialsProvider;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyCheckResult;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyStatus;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\PartnerInfo;

/** Дані шаблону блоку доступу; з токена виходять лише чотири останні символи. */
final class KeySettingsView
{
    private const int TAIL = 4;

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly CredentialsProvider $credentials,
        private readonly KeyChecker $checker,
        private readonly AuthFlags $flags,
        private readonly \Closure $now,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $credentials = $this->credentials->get();
        $partner = $this->checker->partner();

        return [
            'saved' => $credentials->configured(),
            // Короткий токен цілком збігся б зі своїм «хвостом»: тоді не показуємо нічого.
            'token_tail' => strlen($credentials->token) > 2 * self::TAIL ? substr($credentials->token, -self::TAIL) : null,
            'token_id' => $credentials->tokenId,
            'last_check' => self::describe($this->checker->lastResult()),
            'partner' => $partner === null ? null : self::partner($partner),
            'warnings' => $partner === null ? [] : self::warnings($partner, ($this->now)()),
            'flags' => $this->flags->items(),
        ];
    }

    /** @return ?array{auth: string, checked_at: string, has_problem: bool} */
    private static function describe(?KeyCheckResult $result): ?array
    {
        if ($result === null) {
            return null;
        }

        return [
            'auth' => $result->auth->value,
            'checked_at' => $result->checkedAt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i'),
            'has_problem' => $result->auth !== KeyStatus::Ok,
        ];
    }

    /** @return array<string, mixed> */
    private static function partner(PartnerInfo $partner): array
    {
        $flag = static fn(?bool $value): string => $value === null ? 'unknown' : ($value ? 'yes' : 'no');

        return [
            'name' => $partner->name,
            'status' => $partner->status,
            'is_legal' => $flag($partner->isLegal),
            'has_prepayment' => $flag($partner->hasPrepayment),
            'contract_number' => $partner->contractNumber,
            'contract_expires' => self::date($partner->contractExpires),
            'autoblock_days' => $partner->autoblockDays,
        ];
    }

    /** @return list<array{key: string, value: string}> */
    private static function warnings(PartnerInfo $partner, int $now): array
    {
        $values = [
            'sviat__rozetka__warn_partner_status' => $partner->status,
            'sviat__rozetka__warn_autoblock' => (string)$partner->autoblockDays,
            'sviat__rozetka__warn_contract_expires' => self::date($partner->contractExpires) ?? '',
        ];

        return array_map(static fn(string $key): array => ['key' => $key, 'value' => $values[$key] ?? ''], $partner->warnings($now));
    }

    private static function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value)->format('d.m.Y');
        } catch (\Exception) {
            return null;
        }
    }
}
