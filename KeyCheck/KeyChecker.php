<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\KeyCheck;

use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Credentials;
use Okay\Modules\Sviat\RozetkaDelivery\Api\CredentialsProvider;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Api\TokenProvider;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;

/** Перевіряє статичний токен і стан партнера одним запитом `auth/verify`. */
final class KeyChecker
{
    public const string STATE = 'key_check';
    public const string PARTNER_STATE = 'partner';
    /** Менеджер чекає на сторінку: перевірка має закінчитися задовго до тайм-ауту проксі. */
    public const int TIMEOUT_SECONDS = 8;

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly RozetkaClient $client,
        private readonly TokenProvider $tokens,
        private readonly CredentialsProvider $credentials,
        private readonly StateStorage $state,
        private readonly AuthFlags $flags,
        private readonly \Closure $now,
    ) {
    }

    /**
     * Ручна перевірка йде в мережу попри паузу після 401: менеджер міг виправити токен поза модулем.
     *
     * @return array{result: KeyCheckResult, message: ?string} message — текст Rozetka лише для показу, у стан не пишеться
     */
    public function check(): array
    {
        $this->tokens->reset();
        $out = $this->inspect($this->client->quick(self::TIMEOUT_SECONDS), $this->credentials->get(), $this->lastResult()?->passedAt);
        $this->remember($out['result']);

        return $out;
    }

    /**
     * Перевіряє токен, якого ще немає в налаштуваннях, і нічого не пише: результат стає станом
     * лише через remember(), коли токен справді збережено.
     *
     * @return array{result: KeyCheckResult, message: ?string}
     */
    public function probe(Credentials $candidate): array
    {
        return $this->inspect($this->client->withCredentials($candidate)->quick(self::TIMEOUT_SECONDS), $candidate, null);
    }

    /** Прийнятий токен знімає позначки відмови всіх шляхів: вони стосувалися токена, який Rozetka тепер приймає. */
    public function remember(KeyCheckResult $result): void
    {
        if ($result->auth === KeyStatus::Ok) {
            $this->flags->clear(AuthFlags::TOKEN, AuthFlags::TRACKING);
        }
        if ($result->partner !== null) {
            $this->state->set(self::PARTNER_STATE, $result->partner->encode());
        }
        $this->state->set(self::STATE, $result->toJson());
    }

    public function lastResult(): ?KeyCheckResult
    {
        return KeyCheckResult::fromJson($this->state->get(self::STATE));
    }

    public function partner(): ?PartnerInfo
    {
        return PartnerInfo::decode($this->state->get(self::PARTNER_STATE));
    }

    /** @return array{result: KeyCheckResult, message: ?string} */
    private function inspect(RozetkaClient $client, Credentials $credentials, ?string $passedBefore): array
    {
        $partner = null;
        $message = null;

        if (!$credentials->configured()) {
            $auth = KeyStatus::NotConfigured;
        } else {
            try {
                $data = $client->get('auth/verify')['data'];
                $partner = PartnerInfo::fromVerify(is_array($data) ? $data : []);
                $auth = KeyStatus::Ok;
            } catch (RozetkaApiException $e) {
                $message = $e->apiMessage;
                $auth = match ($e->kind) {
                    ApiErrorKind::BadToken => KeyStatus::BadToken,
                    ApiErrorKind::NotConfigured => KeyStatus::NotConfigured,
                    ApiErrorKind::Rejected, ApiErrorKind::NotFound => KeyStatus::Rejected,
                    default => KeyStatus::Unreachable,
                };
            }
        }

        $at = ($this->now)();
        $passedAt = $passedBefore ?? ($auth === KeyStatus::Ok ? gmdate('Y-m-d\TH:i:s\Z', $at) : null);

        return ['result' => new KeyCheckResult($auth, $partner, new \DateTimeImmutable('@' . $at), $passedAt), 'message' => $message];
    }
}
