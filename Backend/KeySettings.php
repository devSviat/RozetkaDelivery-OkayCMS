<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Backend;

use Okay\Core\Settings;
use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Credentials;
use Okay\Modules\Sviat\RozetkaDelivery\Api\CredentialsProvider;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Api\SecretSetting;
use Okay\Modules\Sviat\RozetkaDelivery\Api\TokenIssuer;
use Okay\Modules\Sviat\RozetkaDelivery\Api\TokenProvider;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyCheckResult;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyStatus;
use Okay\Modules\Sviat\RozetkaDelivery\Storage\StateStorage;

/**
 * Блок доступу сторінки налаштувань: токен, вставлений вручну чи випущений формою, перевіряється
 * `auth/verify` до запису; збережений — кнопкою «Перевірити доступ».
 */
final class KeySettings
{
    public const string TOKEN_FIELD = 'token';
    public const string PHONE_FIELD = 'issue_phone';
    public const string PASSWORD_FIELD = 'issue_password';

    public function __construct(
        private readonly Settings $settings,
        private readonly TokenProvider $tokens,
        private readonly StateStorage $state,
        private readonly AuthFlags $flags,
        private readonly KeyChecker $checker,
        private readonly TokenIssuer $issuer,
        private readonly RozetkaClient $client,
        private readonly CredentialsProvider $credentials,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{errors: array<string, string>, check: ?array{result: KeyCheckResult, message: ?string}}
     *     errors — поле → мовний ключ; якщо вони є, нічого не зберігається
     */
    public function save(array $post): array
    {
        $posted = is_string($post[self::TOKEN_FIELD] ?? null) ? Credentials::normalizeToken($post[self::TOKEN_FIELD]) : '';
        // Токен іде в заголовок: пробіл чи перенесення рядка всередині зламали б запит або дописали б інший заголовок.
        if ($posted !== '' && !self::headerSafe($posted)) {
            return ['errors' => [self::TOKEN_FIELD => 'sviat__rozetka__err_token_chars'], 'check' => null];
        }

        $stored = $this->credentials->get()->token;
        $token = SecretSetting::next($stored, $posted, $post['clear_' . self::TOKEN_FIELD] ?? null);
        $check = null;
        if ($token !== '' && $token !== $stored) {
            $check = $this->checker->probe(new Credentials($token));
            // Токен, який Rozetka відхиляє, не повинен замінити той, що ще може працювати.
            if ($check['result']->auth === KeyStatus::BadToken) {
                return ['errors' => [self::TOKEN_FIELD => 'sviat__rozetka__err_token_rejected'], 'check' => null];
            }
        }

        if ($token !== $stored) {
            // Вставлений вручну токен модуль не випускав: його номер невідомий, відкликати його можна лише в кабінеті.
            $this->store($token, null);
        }
        if ($check !== null) {
            $this->checker->remember($check['result']);
        }

        return ['errors' => [], 'check' => $check];
    }

    /**
     * Звертається до Rozetka; викликається лише кнопкою «Перевірити доступ».
     *
     * @return array{result: KeyCheckResult, message: ?string}
     */
    public function check(): array
    {
        return $this->checker->check();
    }

    /**
     * Разова форма «Випустити токен». Пароль живе лише в цьому виклику й TokenIssuer.
     *
     * @param array<string, mixed> $post
     * @return array{errors: array<string, string>, warning: ?string}
     */
    public function issue(array $post): array
    {
        $phone = self::phone(is_string($post[self::PHONE_FIELD] ?? null) ? $post[self::PHONE_FIELD] : '');
        $password = is_string($post[self::PASSWORD_FIELD] ?? null) ? $post[self::PASSWORD_FIELD] : '';
        $errors = array_filter([
            self::PHONE_FIELD => $phone === null ? 'sviat__rozetka__issue_err_phone' : null,
            self::PASSWORD_FIELD => $password === '' ? 'sviat__rozetka__issue_err_password' : null,
        ]);
        if ($phone === null || $errors !== []) {
            return ['errors' => $errors, 'warning' => null];
        }

        $previousId = $this->credentials->get()->tokenId;
        $result = $this->issuer->issue($phone, $password, !empty($post['revoke_previous']), $previousId);
        if ($result->errorKey !== null || $result->token === null) {
            return ['errors' => ['issue' => $result->errorKey ?? 'sviat__rozetka__issue_err_static'], 'warning' => null];
        }
        if (!self::headerSafe($result->token)) {
            return ['errors' => ['issue' => 'sviat__rozetka__issue_err_static'], 'warning' => null];
        }

        $check = $this->checker->probe(new Credentials($result->token, $result->id));
        // Лише явна відмова скасовує запис: недоступна перевірка не повинна губити вже випущений токен.
        if (in_array($check['result']->auth, [KeyStatus::BadToken, KeyStatus::Rejected], true)) {
            return ['errors' => ['issue' => 'sviat__rozetka__issue_err_verify'], 'warning' => $result->warningKey];
        }
        $this->store($result->token, $result->id);
        $this->checker->remember($check['result']);

        return ['errors' => [], 'warning' => $result->warningKey];
    }

    /**
     * «Відкликати токен»: `DELETE partner/static-token/{id}` самим токеном, потім стирання з налаштувань.
     * Іде в мережу попри паузу після 401: пауза локальна, а токен у Rozetka може ще діяти.
     *
     * @return array{errors: array<string, string>}
     */
    public function revoke(): array
    {
        $credentials = $this->credentials->get();
        if (!$credentials->configured()) {
            return ['errors' => ['revoke' => 'sviat__rozetka__revoke_err_no_token']];
        }
        if ($credentials->tokenId === null) {
            return ['errors' => ['revoke' => 'sviat__rozetka__revoke_err_no_id']];
        }
        try {
            $this->client->withCredentials($credentials)->delete("partner/static-token/{$credentials->tokenId}");
        } catch (RozetkaApiException $e) {
            // 401 — токен уже не діє, 404 — його вже видалили в кабінеті: в обох випадках стерти локально безпечно.
            if (!in_array($e->kind, [ApiErrorKind::BadToken, ApiErrorKind::NotFound], true)) {
                return ['errors' => ['revoke' => 'sviat__rozetka__revoke_err']];
            }
        }
        $this->store('', null);

        return ['errors' => []];
    }

    /** `380XXXXXXXXX` з того, як телефон зазвичай вводять: пробіли, дужки, дефіси, «+», початкові 0 чи 80. */
    public static function phone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $digits = match (true) {
            strlen($digits) === 10 && str_starts_with($digits, '0') => '38' . $digits,
            strlen($digits) === 11 && str_starts_with($digits, '80') => '3' . $digits,
            default => $digits,
        };

        return preg_match('/^380\d{9}$/', $digits) === 1 ? $digits : null;
    }

    private static function headerSafe(string $token): bool
    {
        return preg_match('/^[\x21-\x7E]+$/', $token) === 1;
    }

    /** Новий токен: пауза, позначки відмов, результат перевірки й дані партнера описували попередній. */
    private function store(string $token, ?int $tokenId): void
    {
        $this->settings->set(Credentials::TOKEN, $token);
        $this->settings->set(Credentials::TOKEN_ID, $tokenId ?? '');
        $this->tokens->reset();
        $this->flags->clear(AuthFlags::TOKEN, AuthFlags::TRACKING);
        $this->state->set(KeyChecker::STATE, null);
        $this->state->set(KeyChecker::PARTNER_STATE, null);
    }
}
