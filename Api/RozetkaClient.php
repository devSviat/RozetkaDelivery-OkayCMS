<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Api;

use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\HttpRequest;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\HttpTransport;
use Psr\Log\LoggerInterface;

/**
 * Єдина точка виходу в ROZETKA Delivery API: Bearer-токен, бюджет запитів, пауза після 401 і маскування секретів.
 * Повтору після 401 немає: статичний токен автоматично не оновлюється.
 */
final class RozetkaClient
{
    use SendsRequests;

    public const int DEFAULT_TIMEOUT = 20;
    /**
     * Як API читає масиви в query, схема не каже; `id[]=a&id[]=b` — форма Swagger UI для `style: form`.
     * Єдине місце для зміни після живої перевірки.
     */
    public const string ARRAY_STYLE = '[]';

    private int $timeoutSeconds = self::DEFAULT_TIMEOUT;
    private ?Credentials $candidate = null;
    /** @var list<string> */
    private array $extraSecrets = [];

    public function __construct(
        private readonly HttpTransport $http,
        private readonly TokenProvider $tokens,
        private readonly RequestBudget $budget,
        private readonly CredentialsProvider $credentials,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Короткий тайм-аут для відповідей, на які хтось чекає: перевірка доступу, дата в кошику. */
    public function quick(int $timeoutSeconds): self
    {
        $client = clone $this;
        $client->timeoutSeconds = $timeoutSeconds;

        return $client;
    }

    /**
     * Запити з токеном, якого ще немає в налаштуваннях: перевірка до збереження.
     * Відмова такого токена не ставить паузу й позначку збереженому: він міг і далі працювати.
     */
    public function withCredentials(Credentials $credentials): self
    {
        $client = clone $this;
        $client->candidate = $credentials;

        return $client;
    }

    /**
     * Сесія форми випуску токена: інший Bearer (JWT з auth/login) без паузи й позначок збереженого токена.
     *
     * @param list<string> $secrets що ще маскувати в текстах на час сесії (телефон, пароль)
     */
    public function withBearer(string $jwt, array $secrets = []): self
    {
        return $this->withCredentials(new Credentials($jwt))->withSecrets($secrets);
    }

    /**
     * Що ще маскувати в текстах помилок і логу, крім токенів.
     *
     * @param list<string> $secrets
     */
    public function withSecrets(array $secrets): self
    {
        $client = clone $this;
        $client->extraSecrets = array_values(array_filter($secrets, static fn(string $s): bool => $s !== ''));

        return $client;
    }

    /**
     * @param array<string, scalar|list<scalar>> $query
     * @return array{data: mixed, pagination: ?array<mixed>}
     * @throws RozetkaApiException
     */
    public function get(string $path, array $query = [], bool $auth = true, string $slot = RequestBudget::OTHER): array
    {
        return $this->send('GET', $path . self::query($query), null, $auth, $slot);
    }

    /**
     * @param array<mixed> $body
     * @return array{data: mixed, pagination: ?array<mixed>}
     * @throws RozetkaApiException
     */
    public function post(string $path, array $body, bool $auth = true): array
    {
        return $this->send('POST', $path, $body, $auth, RequestBudget::OTHER);
    }

    /**
     * @param array<mixed> $body
     * @return array{data: mixed, pagination: ?array<mixed>}
     * @throws RozetkaApiException
     */
    public function patch(string $path, array $body): array
    {
        return $this->send('PATCH', $path, $body, true, RequestBudget::OTHER);
    }

    /**
     * @param array<mixed>|null $body
     * @return array{data: mixed, pagination: ?array<mixed>}
     * @throws RozetkaApiException
     */
    public function delete(string $path, ?array $body = null): array
    {
        return $this->send('DELETE', $path, $body, true, RequestBudget::OTHER);
    }

    /** @param array<string, scalar|list<scalar>> $query */
    public static function query(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $key = rawurlencode($name) . (is_array($value) ? self::ARRAY_STYLE : '');
                $pairs[] = $key . '=' . rawurlencode(is_bool($item) ? ($item ? 'true' : 'false') : (string)$item);
            }
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /**
     * @param array<mixed>|null $body
     * @return array{data: mixed, pagination: ?array<mixed>}
     */
    private function send(string $httpMethod, string $path, ?array $body, bool $auth, string $slot): array
    {
        $started = hrtime(true);
        $headers = ['Accept' => 'application/json', 'Content-Language' => 'uk'];
        $encoded = null;
        if ($body !== null) {
            // Порожнє тіло — об'єкт: API приймає JSON-об'єкти, а [] — це масив.
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($body === [] ? JSON_FORCE_OBJECT : 0));
            if ($encoded === false) {
                throw $this->failure(ApiErrorKind::Malformed, $httpMethod, $path, null, $started, 'request body is not encodable');
            }
            $headers['Content-Type'] = 'application/json';
        }
        // token() перед budget->take(): під паузою після 401 чи без налаштувань блокування БД не береться й бюджет не витрачається.
        try {
            if ($auth) {
                $headers['Authorization'] = 'Bearer ' . $this->token();
            }
            $this->budget->take($slot);
        } catch (RozetkaApiException $e) {
            $this->logger->warning($this->line($httpMethod, $path, null, $e->kind, $started));
            throw $e;
        }

        $request = new HttpRequest($httpMethod, Credentials::BASE . $path, $headers, $encoded, $this->timeoutSeconds);
        try {
            $response = $this->deliver($this->http, $request, $this->secrets());
        } catch (RozetkaApiException $e) {
            if ($e->kind === ApiErrorKind::BadToken && $auth && $this->candidate === null) {
                $this->tokens->rejected();
            }
            // Статус HTTP лише в тексті винятку trait-а; окремого поля в RozetkaApiException немає.
            $status = preg_match('/: HTTP (\d{3})/', $e->getMessage(), $m) === 1 ? (int)$m[1] : null;
            $this->logger->warning($this->line($httpMethod, $path, $status, $e->kind, $started, $e->field));
            throw $e;
        }

        $json = $response->json();
        if ($json === null) {
            throw $this->failure(ApiErrorKind::Malformed, $httpMethod, $path, $response->status, $started, 'response is not JSON');
        }
        $code = $json['statusCode'] ?? 0;
        if ($code !== 0) {
            [$field, $apiMessage] = self::errorText($json);
            $text = 'statusCode ' . (is_scalar($code) ? (string)$code : '?') . ($apiMessage === null ? '' : " {$apiMessage}");
            throw $this->failure(ApiErrorKind::Rejected, $httpMethod, $path, $response->status, $started, $text, $field, $apiMessage);
        }

        // auth/login і auth/verify за схемою кладуть поля поряд зі statusCode, без обгортки data.
        $data = array_key_exists('data', $json) ? $json['data'] : array_diff_key($json, ['statusCode' => true, 'pagination' => true]);

        return [
            'data' => $data,
            'pagination' => is_array($json['pagination'] ?? null) ? $json['pagination'] : null,
        ];
    }

    /** @throws RozetkaApiException */
    private function token(): string
    {
        if ($this->candidate === null) {
            return $this->tokens->token();
        }
        if (!$this->candidate->configured()) {
            throw RozetkaApiException::of(ApiErrorKind::NotConfigured, 'Rozetka token is not set');
        }

        return $this->candidate->token;
    }

    /** @return list<string> секрети і перевірюваного, і збереженого токена */
    private function secrets(): array
    {
        $secrets = [...$this->credentials->get()->secrets(), ...$this->extraSecrets];
        if ($this->candidate !== null) {
            $secrets = [...$this->candidate->secrets(), ...$secrets];
        }

        return array_values(array_unique($secrets));
    }

    private function failure(
        ApiErrorKind $kind,
        string $httpMethod,
        string $path,
        ?int $status,
        int $started,
        string $text,
        ?string $field = null,
        ?string $apiMessage = null,
    ): RozetkaApiException {
        $secrets = $this->secrets();
        $text = RozetkaApiException::mask($text, $secrets);
        $field = $field === null ? null : RozetkaApiException::mask($field, $secrets);
        $apiMessage = $apiMessage === null ? null : RozetkaApiException::mask($apiMessage, $secrets);
        $this->logger->warning($this->line($httpMethod, $path, $status, $kind, $started, $field));

        return RozetkaApiException::of($kind, RozetkaApiException::mask("{$httpMethod} " . self::bare($path), $secrets) . ": {$text}", $field, $apiMessage);
    }

    /** Лише метод, шлях без query, код, вид і тривалість: тіла несуть ПІБ і телефони одержувачів. */
    private function line(string $httpMethod, string $path, ?int $status, ApiErrorKind $kind, int $started, ?string $field = null): string
    {
        $line = "Rozetka {$httpMethod} " . self::bare($path)
            . ($status === null ? '' : " HTTP {$status}")
            . " kind={$kind->value}"
            . ($field === null ? '' : " field={$field}")
            . ' ms=' . intdiv(hrtime(true) - $started, 1_000_000);

        return RozetkaApiException::mask($line, $this->secrets());
    }

    private static function bare(string $path): string
    {
        return explode('?', $path, 2)[0];
    }
}
