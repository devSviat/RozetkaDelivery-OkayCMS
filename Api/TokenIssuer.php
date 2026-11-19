<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Api;

use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;

/**
 * Разовий випуск статичного токена за телефоном і паролем кабінету: login → static-token →
 * (відкликання попереднього) → logout. Пароль і JWT живуть лише в змінних цього виклику;
 * налаштувань клас не пише — новий токен ще має пройти auth/verify (KeySettings).
 */
final class TokenIssuer
{
    /** Адмінка не повинна ставати інструментом підбору пароля кабінету. */
    public const int FAIL_LIMIT = 5;
    public const int FAIL_WINDOW = 900;

    private const string FAILURES = 'issue_failures';

    /** @param \Closure(): int $now */
    public function __construct(
        private readonly RozetkaClient $client,
        private readonly StateStorage $state,
        private readonly \Closure $now,
    ) {
    }

    public function issue(string $phone, string $password, bool $revokePrevious, ?int $previousId): IssueResult
    {
        $failures = $this->recentFailures();
        if (count($failures) >= self::FAIL_LIMIT) {
            return IssueResult::error('sviat__rozetka__issue_err_too_many');
        }

        $secrets = [$phone, $password];
        $login = $this->client->withSecrets($secrets);
        try {
            $data = $login->post('auth/login', ['phone' => $phone, 'password' => $password], auth: false)['data'];
        } catch (RozetkaApiException $e) {
            $this->state->set(self::FAILURES, json_encode([...$failures, ($this->now)()]));

            return IssueResult::error(match ($e->kind) {
                ApiErrorKind::BadToken, ApiErrorKind::Rejected, ApiErrorKind::NotFound => 'sviat__rozetka__issue_err_credentials',
                default => 'sviat__rozetka__issue_err_unreachable',
            });
        }
        $jwt = is_array($data) && is_string($data['access_token'] ?? null) ? $data['access_token'] : '';
        if ($jwt === '') {
            return IssueResult::error('sviat__rozetka__issue_err_unreachable');
        }

        $session = $this->client->withBearer($jwt, $secrets);
        try {
            return $this->staticToken($session, $jwt, $secrets, $revokePrevious, $previousId);
        } finally {
            // Сесія закривається завжди: відкритий JWT живе 72 години від останнього використання.
            try {
                $session->delete('auth/logout');
            } catch (RozetkaApiException) {
            }
        }
    }

    /** @param list<string> $secrets */
    private function staticToken(RozetkaClient $session, string $jwt, array $secrets, bool $revokePrevious, ?int $previousId): IssueResult
    {
        try {
            $data = $session->post('partner/static-token', [])['data'];
        } catch (RozetkaApiException) {
            return IssueResult::error('sviat__rozetka__issue_err_static');
        }
        $token = is_array($data) && is_string($data['token'] ?? null) ? Credentials::normalizeToken($data['token']) : '';
        $id = is_array($data) && (is_int($data['id'] ?? null) || is_float($data['id'] ?? null)) ? (int)$data['id'] : 0;
        if ($token === '' || $id <= 0) {
            return IssueResult::error('sviat__rozetka__issue_err_static');
        }

        $warning = null;
        if ($revokePrevious && $previousId !== null && $previousId !== $id) {
            // Попередній відкликається лише після того, як новий пройшов auth/verify: інакше збій перевірки
            // лишив би магазин без жодного робочого токена.
            try {
                $this->client->withCredentials(new Credentials($token))->withSecrets($secrets)->get('auth/verify');
                $this->client->withBearer($jwt, [...$secrets, $token])->delete("partner/static-token/{$previousId}");
            } catch (RozetkaApiException) {
                $warning = 'sviat__rozetka__issue_warn_revoke';
            }
        }

        return new IssueResult($token, $id, null, $warning);
    }

    /** @return list<int> часові мітки невдалих входів у вікні; телефон не зберігається */
    private function recentFailures(): array
    {
        $saved = json_decode((string)$this->state->get(self::FAILURES), true);
        $since = ($this->now)() - self::FAIL_WINDOW;

        return is_array($saved) ? array_values(array_filter($saved, static fn(mixed $at): bool => is_int($at) && $at > $since)) : [];
    }
}
