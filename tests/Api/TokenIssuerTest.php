<?php

namespace Modules\Sviat\RozetkaDelivery\Api;

require_once __DIR__ . '/../Support/ApiWorld.php';

use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\TransportException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\IssueResult;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\TokenIssuer;
use PHPUnit\Framework\TestCase;

class TokenIssuerTest extends TestCase
{
    private const string PHONE = '380501112233';
    private const string PASSWORD = 'pw-123';
    private const string JWT = 'jwt-abc';
    private const string NEW_TOKEN = 'tok-new-789';

    private ApiWorld $world;

    protected function setUp(): void
    {
        $this->world = new ApiWorld();
    }

    private function issuer(): TokenIssuer
    {
        return new TokenIssuer($this->world->client(), $this->world->state, $this->world->clock());
    }

    private function login(): void
    {
        $this->world->http->json(201, ['statusCode' => 0, 'access_token' => self::JWT, 'refresh_token' => 'jwt-refresh', 'status' => 'active']);
    }

    private function ok(): void
    {
        $this->world->http->json(200, ['statusCode' => 0]);
    }

    /** @return list<string> */
    private function calls(): array
    {
        return array_map(
            static fn($r): string => $r->method . ' ' . substr($r->url, strlen('https://rz-delivery.rozetka.ua/api/')),
            $this->world->http->requests(),
        );
    }

    public function testHappyPathReturnsStaticToken(): void
    {
        $this->login();
        $this->world->http->json(201, ['statusCode' => 0, 'data' => ['id' => 12, 'token' => self::NEW_TOKEN]]);
        $this->ok();

        $result = $this->issuer()->issue(self::PHONE, self::PASSWORD, false, 7);

        self::assertSame(['POST auth/login', 'POST partner/static-token', 'DELETE auth/logout'], $this->calls());
        $requests = $this->world->http->requests();
        self::assertArrayNotHasKey('Authorization', $requests[0]->headers);
        self::assertSame(['phone' => self::PHONE, 'password' => self::PASSWORD], json_decode((string)$requests[0]->body, true));
        self::assertSame('Bearer ' . self::JWT, $requests[1]->headers['Authorization']);
        self::assertSame('Bearer ' . self::JWT, $requests[2]->headers['Authorization']);
        self::assertSame(self::NEW_TOKEN, $result->token);
        self::assertSame(12, $result->id);
        self::assertNull($result->errorKey);
        self::assertNull($result->warningKey);
    }

    public function testLogoutEvenWhenStaticTokenFails(): void
    {
        $this->login();
        $this->world->http->json(400, ['statusCode' => 400, 'message' => 'Ліміт токенів']);
        $this->world->http->push(new TransportException('logout timeout'));

        $result = $this->issuer()->issue(self::PHONE, self::PASSWORD, false, null);

        self::assertSame(['POST auth/login', 'POST partner/static-token', 'DELETE auth/logout'], $this->calls());
        self::assertNull($result->token);
        self::assertSame('sviat__rozetka__issue_err_static', $result->errorKey);
    }

    public function testMalformedStaticTokenIsAnError(): void
    {
        $this->login();
        $this->world->http->json(201, ['statusCode' => 0, 'data' => ['id' => 'x', 'token' => '']]);
        $this->ok();

        $result = $this->issuer()->issue(self::PHONE, self::PASSWORD, false, null);

        self::assertNull($result->token);
        self::assertSame('sviat__rozetka__issue_err_static', $result->errorKey);
    }

    public function testPasswordAndJwtNowhere(): void
    {
        $this->login();
        $this->world->http->json(500, ['statusCode' => 500, 'message' => 'bad ' . self::JWT . ' ' . self::PASSWORD . ' ' . self::PHONE]);
        $this->world->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized ' . self::JWT]);

        $result = $this->issuer()->issue(self::PHONE, self::PASSWORD, false, null);

        $everything = json_encode([$this->world->state->values, (array)$result], JSON_UNESCAPED_UNICODE) . $this->world->logger->text();
        foreach ([self::PASSWORD, self::JWT, 'jwt-refresh'] as $secret) {
            self::assertStringNotContainsString($secret, $everything);
        }
        self::assertStringNotContainsString(self::PHONE, json_encode($this->world->state->values) . $this->world->logger->text());
    }

    public function testExceptionTextNeverCarriesSecrets(): void
    {
        $this->world->http->json(400, ['statusCode' => 400, 'message' => 'bad ' . self::JWT . ' ' . self::PASSWORD]);

        $session = $this->world->client()->withBearer(self::JWT, [self::PHONE, self::PASSWORD]);
        try {
            $session->post('partner/static-token', []);
            self::fail('expected an exception');
        } catch (RozetkaApiException $e) {
            self::assertStringNotContainsString(self::JWT, $e->getMessage() . $e->apiMessage);
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage() . $e->apiMessage);
        }
    }

    public function testWrongPasswordGivesKeyNotApiText(): void
    {
        $this->world->http->json(401, ['statusCode' => 401, 'message' => 'Невірний пароль для 380501112233']);

        $result = $this->issuer()->issue(self::PHONE, self::PASSWORD, false, null);

        self::assertSame('sviat__rozetka__issue_err_credentials', $result->errorKey);
        self::assertSame(['POST auth/login'], $this->calls(), 'no session, nothing else, no logout');
        self::assertStringNotContainsString('Невірний', json_encode((array)$result, JSON_UNESCAPED_UNICODE));
        self::assertNull($this->world->state->get('token_failed_at'), 'login refusal does not pause the stored token');
    }

    public function testUnreachableLoginIsItsOwnError(): void
    {
        $this->world->http->push(new TransportException('timeout'));

        self::assertSame('sviat__rozetka__issue_err_unreachable', $this->issuer()->issue(self::PHONE, self::PASSWORD, false, null)->errorKey);
    }

    public function testSixthFailureWithinWindowSendsNothing(): void
    {
        for ($i = 0; $i < TokenIssuer::FAIL_LIMIT; $i++) {
            $this->world->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized']);
            $this->issuer()->issue(self::PHONE, self::PASSWORD, false, null);
            $this->world->now += 60;
        }

        $result = $this->issuer()->issue(self::PHONE, self::PASSWORD, false, null);

        self::assertSame('sviat__rozetka__issue_err_too_many', $result->errorKey);
        self::assertCount(TokenIssuer::FAIL_LIMIT, $this->world->http->requests());
        self::assertStringNotContainsString(self::PHONE, (string)$this->world->state->get('issue_failures'));

        $this->world->now = ApiWorld::START + TokenIssuer::FAIL_WINDOW;
        $this->world->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized']);
        self::assertSame('sviat__rozetka__issue_err_credentials', $this->issuer()->issue(self::PHONE, self::PASSWORD, false, null)->errorKey, 'the oldest failure left the window');
    }

    public function testRevokesPreviousWhenAsked(): void
    {
        $this->login();
        $this->world->http->json(201, ['statusCode' => 0, 'data' => ['id' => 12, 'token' => self::NEW_TOKEN]]);
        $this->world->http->json(200, ['statusCode' => 0, 'id' => 1, 'status' => 'active', 'name' => 'x']);
        $this->ok();
        $this->ok();

        $result = $this->issuer()->issue(self::PHONE, self::PASSWORD, true, 7);

        self::assertSame(['POST auth/login', 'POST partner/static-token', 'GET auth/verify', 'DELETE partner/static-token/7', 'DELETE auth/logout'], $this->calls());
        self::assertSame('Bearer ' . self::NEW_TOKEN, $this->world->http->requests()[2]->headers['Authorization']);
        self::assertSame('Bearer ' . self::JWT, $this->world->http->requests()[3]->headers['Authorization']);
        self::assertNull($result->warningKey);
    }

    public function testNoRevokeWithoutPreviousOrSameId(): void
    {
        $this->login();
        $this->world->http->json(201, ['statusCode' => 0, 'data' => ['id' => 7, 'token' => self::NEW_TOKEN]]);
        $this->ok();

        $this->issuer()->issue(self::PHONE, self::PASSWORD, true, 7);

        self::assertSame(['POST auth/login', 'POST partner/static-token', 'DELETE auth/logout'], $this->calls());
    }

    public function testRevokeFailureIsWarningOnly(): void
    {
        $this->login();
        $this->world->http->json(201, ['statusCode' => 0, 'data' => ['id' => 12, 'token' => self::NEW_TOKEN]]);
        $this->world->http->json(200, ['statusCode' => 0, 'id' => 1, 'status' => 'active', 'name' => 'x']);
        $this->world->http->json(404, ['statusCode' => 404, 'message' => 'Not found ' . self::NEW_TOKEN]);
        $this->ok();

        $result = $this->issuer()->issue(self::PHONE, self::PASSWORD, true, 7);

        self::assertSame(self::NEW_TOKEN, $result->token);
        self::assertSame('sviat__rozetka__issue_warn_revoke', $result->warningKey);
        self::assertNull($result->errorKey);
        self::assertStringNotContainsString(self::NEW_TOKEN, $this->world->logger->text());
    }

    /** Попередній токен не відкликається, доки новий не пройшов auth/verify: інакше магазин лишився б без доступу. */
    public function testRevokesOnlyAfterNewTokenVerified(): void
    {
        $this->login();
        $this->world->http->json(201, ['statusCode' => 0, 'data' => ['id' => 12, 'token' => self::NEW_TOKEN]]);
        $this->world->http->json(503, ['statusCode' => 503]);
        $this->ok();

        $result = $this->issuer()->issue(self::PHONE, self::PASSWORD, true, 7);

        self::assertSame(['POST auth/login', 'POST partner/static-token', 'GET auth/verify', 'DELETE auth/logout'], $this->calls());
        self::assertSame(self::NEW_TOKEN, $result->token);
        self::assertSame('sviat__rozetka__issue_warn_revoke', $result->warningKey);
    }

    public function testResultHasNoJwt(): void
    {
        $names = array_map(static fn(\ReflectionProperty $p): string => $p->getName(), new \ReflectionClass(IssueResult::class)->getProperties());

        self::assertSame(['token', 'id', 'errorKey', 'warningKey'], $names);
    }
}
