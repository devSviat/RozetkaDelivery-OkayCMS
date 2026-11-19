<?php

namespace Modules\Sviat\RozetkaDelivery\Api;

require_once __DIR__ . '/../Support/FakeTransport.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';

use Modules\Sviat\RozetkaDelivery\Support\ArrayLogger;
use Modules\Sviat\RozetkaDelivery\Support\FakeTransport;
use Modules\Sviat\RozetkaDelivery\Support\FixedCredentials;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryMutex;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryState;
use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Credentials;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\HttpResponse;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\TransportException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RequestBudget;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Api\TokenProvider;
use PHPUnit\Framework\TestCase;

class RozetkaClientTest extends TestCase
{
    private const int START = 1_800_000_000;
    private const string TOKEN = 'tok-123';

    private FakeTransport $http;
    private InMemoryState $state;
    private ArrayLogger $logger;
    private int $now = self::START;

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->state = new InMemoryState();
        $this->logger = new ArrayLogger();
        $this->now = self::START;
    }

    private function client(string $token = self::TOKEN): RozetkaClient
    {
        $credentials = new FixedCredentials(new Credentials($token, 7));
        $now = fn(): int => $this->now;

        return new RozetkaClient(
            $this->http,
            new TokenProvider($credentials, $this->state, new AuthFlags($this->state), $now),
            new RequestBudget($this->state, new InMemoryMutex(), $now),
            $credentials,
            $this->logger,
        );
    }

    private function failure(callable $call): RozetkaApiException
    {
        try {
            $call();
        } catch (RozetkaApiException $e) {
            return $e;
        }
        self::fail('expected RozetkaApiException');
    }

    public function testAny2xxAccepted(): void
    {
        $this->http->json(200, ['statusCode' => 0, 'data' => ['a' => 1]])->json(201, ['statusCode' => 0, 'data' => ['b' => 2]]);

        self::assertSame(['a' => 1], $this->client()->get('auth/verify')['data']);
        self::assertSame(['b' => 2], $this->client()->post('track', ['x' => 1])['data']);
    }

    public function testRequestShape(): void
    {
        $this->http->json(201, ['statusCode' => 0, 'data' => []]);

        $this->client()->post('track', ['description' => 'Замовлення №5']);

        $request = $this->http->requests()[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://rz-delivery.rozetka.ua/api/track', $request->url);
        self::assertSame('Bearer tok-123', $request->headers['Authorization']);
        self::assertSame('uk', $request->headers['Content-Language']);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertSame('{"description":"Замовлення №5"}', $request->body);
    }

    public function testNonZeroStatusCodeRejected(): void
    {
        $this->http->json(200, ['statusCode' => 3, 'message' => 'Відмовлено']);

        $e = $this->failure(fn() => $this->client()->get('auth/verify'));

        self::assertSame(ApiErrorKind::Rejected, $e->kind);
        self::assertSame('Відмовлено', $e->apiMessage);
    }

    public function testValidationDetailsParsed(): void
    {
        $this->http->json(400, [
            'statusCode' => 400,
            'message' => 'Помилка валідації даних',
            'details' => [['property' => 'limit', 'children' => [], 'constraints' => ['max' => 'limit must not be greater than 100']]],
            'error' => 'Bad Request',
        ]);

        $e = $this->failure(fn() => $this->client()->get('department', ['limit' => 101], auth: false));

        self::assertSame(ApiErrorKind::Rejected, $e->kind);
        self::assertSame('limit', $e->field);
        self::assertSame('limit must not be greater than 100', $e->apiMessage);
    }

    public function testMessageObjectOrString(): void
    {
        $this->http->json(403, ['statusCode' => 403, 'message' => 'Forbidden'])
            ->json(400, ['statusCode' => 400, 'message' => ['uk' => 'Договір не активний'], 'error' => 'Bad Request']);

        self::assertSame('Forbidden', $this->failure(fn() => $this->client()->get('auth/verify'))->apiMessage);
        self::assertSame('Договір не активний', $this->failure(fn() => $this->client()->get('auth/verify'))->apiMessage);
    }

    public function test401PausesAndNoRetry(): void
    {
        $this->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized']);

        $e = $this->failure(fn() => $this->client()->get('auth/verify'));

        self::assertSame(ApiErrorKind::BadToken, $e->kind);
        self::assertCount(1, $this->http->requests());
        self::assertSame((string)self::START, $this->state->get('token_failed_at'));
        self::assertSame('bad_token', new AuthFlags($this->state)->reason(AuthFlags::TOKEN));
        self::assertSame(ApiErrorKind::BadToken, $this->failure(fn() => $this->client()->get('auth/verify'))->kind);
        self::assertCount(1, $this->http->requests(), 'paused: no second request');
    }

    public function testPublicMethodHasNoAuthHeader(): void
    {
        $this->http->json(200, ['statusCode' => 0, 'data' => []]);

        $this->client()->get('department', ['page' => 1], auth: false);

        self::assertArrayNotHasKey('Authorization', $this->http->requests()[0]->headers);
    }

    public function testPublicMethodWorksWithoutTokenAndDuringPause(): void
    {
        $this->http->json(200, ['statusCode' => 0, 'data' => []])->json(200, ['statusCode' => 0, 'data' => []]);
        $this->client('')->get('track-status', auth: false);
        $this->state->set('token_failed_at', (string)self::START);

        $this->client()->get('department', auth: false);
        self::assertCount(2, $this->http->requests());
    }

    public function testPublic401DoesNotPause(): void
    {
        $this->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized']);

        self::assertSame(ApiErrorKind::BadToken, $this->failure(fn() => $this->client()->get('track/abc', auth: false))->kind);
        self::assertNull($this->state->get('token_failed_at'));
        self::assertNull(new AuthFlags($this->state)->reason(AuthFlags::TOKEN));
    }

    public function testNotConfiguredSendsNothing(): void
    {
        self::assertSame(ApiErrorKind::NotConfigured, $this->failure(fn() => $this->client('')->get('auth/verify'))->kind);
        self::assertSame([], $this->http->requests());
    }

    public function testArrayQueryStyle(): void
    {
        $this->http->json(200, ['statusCode' => 0, 'data' => []]);

        $this->client()->get('track/status', ['id' => ['a1', 'b 2'], 'page' => 1]);

        self::assertSame('https://rz-delivery.rozetka.ua/api/track/status?id[]=a1&id[]=b%202&page=1', $this->http->requests()[0]->url);
    }

    public function testErrorKinds(): void
    {
        $this->http->json(404, ['statusCode' => 404, 'message' => 'Not Found'])
            ->json(429, ['statusCode' => 429, 'message' => 'Too Many Requests'])
            ->json(503, ['statusCode' => 503])
            ->push(new HttpResponse(200, '<html>'))
            ->push(new TransportException('timeout'));

        self::assertSame(ApiErrorKind::NotFound, $this->failure(fn() => $this->client()->get('track/1'))->kind);
        self::assertSame(ApiErrorKind::RateLimited, $this->failure(fn() => $this->client()->get('track/1'))->kind);
        $this->now++;
        self::assertSame(ApiErrorKind::ServerError, $this->failure(fn() => $this->client()->get('track/1'))->kind);
        self::assertSame(ApiErrorKind::Malformed, $this->failure(fn() => $this->client()->get('track/1'))->kind);
        self::assertSame(ApiErrorKind::Unreachable, $this->failure(fn() => $this->client()->get('track/1'))->kind);
    }

    public function testTokenNeverInLog(): void
    {
        $this->http->json(400, ['statusCode' => 400, 'message' => 'bad token tok-123'])
            ->push(new TransportException('refused Bearer tok-123'));

        $first = $this->failure(fn() => $this->client()->get('auth/verify', ['q' => 'x']));
        $second = $this->failure(fn() => $this->client()->delete('partner/static-token/7'));

        self::assertNotSame('', $this->logger->text());
        self::assertStringNotContainsString('tok-123', $this->logger->text());
        self::assertStringNotContainsString('q=x', $this->logger->text(), 'path without query');
        self::assertMatchesRegularExpression('/GET auth\/verify HTTP 400 kind=rejected ms=\d+/', $this->logger->text());
        self::assertStringNotContainsString('tok-123', $first->getMessage() . $second->getMessage() . $first->apiMessage);
    }

    public function testWithBearerUsesGivenJwtAndSkipsPause(): void
    {
        $this->state->set('token_failed_at', (string)self::START);
        $this->http->json(201, ['statusCode' => 0, 'data' => ['id' => 9, 'token' => 'tok-new']])
            ->json(401, ['statusCode' => 401, 'message' => 'Unauthorized jwt-abc']);

        $client = $this->client('')->withBearer('jwt-abc');
        $client->post('partner/static-token', []);
        $e = $this->failure(fn() => $client->delete('auth/logout'));

        self::assertSame('Bearer jwt-abc', $this->http->requests()[0]->headers['Authorization']);
        self::assertSame(ApiErrorKind::BadToken, $e->kind);
        self::assertSame((string)self::START, $this->state->get('token_failed_at'), 'JWT refusal does not touch the stored token pause');
        self::assertNull(new AuthFlags($this->state)->reason(AuthFlags::TOKEN));
        self::assertStringNotContainsString('jwt-abc', $this->logger->text() . $e->getMessage() . $e->apiMessage);
    }

    public function testCandidateCredentialsDoNotPauseStored(): void
    {
        $this->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized']);

        $e = $this->failure(fn() => $this->client()->withCredentials(new Credentials('tok-456'))->get('auth/verify'));

        self::assertSame(ApiErrorKind::BadToken, $e->kind);
        self::assertSame('Bearer tok-456', $this->http->requests()[0]->headers['Authorization']);
        self::assertNull($this->state->get('token_failed_at'));
    }

    public function testPaginationReturned(): void
    {
        $this->http->json(200, ['statusCode' => 0, 'data' => [['id' => 'x']], 'pagination' => ['total_count' => 1006, 'page_count' => 11, 'page' => 1, 'limit' => 100]])
            ->json(200, ['statusCode' => 0, 'data' => []]);

        $answer = $this->client()->get('department', ['page' => 1, 'limit' => 100], auth: false);

        self::assertSame(['total_count' => 1006, 'page_count' => 11, 'page' => 1, 'limit' => 100], $answer['pagination']);
        self::assertNull($this->client()->get('track-status', auth: false)['pagination']);
    }

    /** Схема описує auth/login і auth/verify без обгортки data: поля лежать поряд зі statusCode. */
    public function testUnwrappedBodyIsData(): void
    {
        $this->http->json(201, ['statusCode' => 0, 'access_token' => 'jwt-abc', 'status' => 'active']);

        self::assertSame(['access_token' => 'jwt-abc', 'status' => 'active'], $this->client()->post('auth/login', ['phone' => '380501112233'], auth: false)['data']);
    }

    public function testEmptyBodyIsSentAsObject(): void
    {
        $this->http->json(201, ['statusCode' => 0, 'data' => []]);

        $this->client()->post('partner/static-token', []);

        self::assertSame('{}', $this->http->requests()[0]->body);
    }

    public function testBudgetRefusalSendsNothing(): void
    {
        for ($i = 0; $i < RequestBudget::LIMIT; $i++) {
            $this->http->json(200, ['statusCode' => 0, 'data' => []]);
            $this->client()->get('track-status', auth: false);
        }

        self::assertSame(ApiErrorKind::RateLimited, $this->failure(fn() => $this->client()->get('track-status', auth: false))->kind);
        self::assertCount(RequestBudget::LIMIT, $this->http->requests());
    }
}
