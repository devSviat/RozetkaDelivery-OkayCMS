<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Api;

use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;

/**
 * Статичний токен партнера для заголовка Authorization і пауза після його відмови.
 * Строк токена модуль не відстежує: прострочений дає 401, а після 401 запити з токеном 15 хв не йдуть у мережу,
 * щоб неправильний токен не множив виклики Rozetka.
 */
final class TokenProvider
{
    public const int PAUSE = 900;

    private const string TOKEN_FAILED = 'token_failed_at';

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly CredentialsProvider $credentials,
        private readonly StateStorage $state,
        private readonly AuthFlags $flags,
        private readonly \Closure $now,
    ) {
    }

    /** @throws RozetkaApiException NotConfigured без токена; BadToken, поки триває пауза після відмови */
    public function token(): string
    {
        $credentials = $this->credentials->get();
        if (!$credentials->configured()) {
            throw RozetkaApiException::of(ApiErrorKind::NotConfigured, 'Rozetka token is not set');
        }
        if ($this->paused()) {
            throw RozetkaApiException::of(ApiErrorKind::BadToken, 'Rozetka token refused recently; paused');
        }

        return $credentials->token;
    }

    /** Rozetka відповіла 401: повтор нічого не змінить, доки менеджер не збереже новий токен чи не перевірить доступ. */
    public function rejected(): void
    {
        $this->flags->record(AuthFlags::TOKEN, RozetkaApiException::of(ApiErrorKind::BadToken, 'Rozetka token rejected'));
        $this->state->set(self::TOKEN_FAILED, (string)($this->now)());
    }

    /** Новий токен чи ручна перевірка доступу: пауза стосувалася попереднього. */
    public function reset(): void
    {
        $this->state->set(self::TOKEN_FAILED, null);
    }

    private function paused(): bool
    {
        $failedAt = $this->state->get(self::TOKEN_FAILED);

        return $failedAt !== null && ctype_digit($failedAt) && ($this->now)() < (int)$failedAt + self::PAUSE;
    }
}
