<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\KeyCheck;

/** Лише вид результату й дані партнера: текст відповіді Rozetka в результат не потрапляє. */
final readonly class KeyCheckResult
{
    public function __construct(
        public KeyStatus $auth,
        public ?PartnerInfo $partner,
        public \DateTimeImmutable $checkedAt,
        public ?string $passedAt,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'auth' => $this->auth->value,
            'at' => $this->checkedAt->getTimestamp(),
            'passed_at' => $this->passedAt,
        ], JSON_THROW_ON_ERROR);
    }

    /** Дані партнера живуть в окремому рядку стану (KeyChecker::PARTNER_STATE), тут їх немає. */
    public static function fromJson(?string $json): ?self
    {
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data) || !is_int($data['at'] ?? null)) {
            return null;
        }
        $auth = KeyStatus::tryFrom((string)($data['auth'] ?? ''));
        if ($auth === null) {
            return null;
        }

        return new self($auth, null, new \DateTimeImmutable('@' . $data['at']), is_string($data['passed_at'] ?? null) ? $data['passed_at'] : null);
    }
}
