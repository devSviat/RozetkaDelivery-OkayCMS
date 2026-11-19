<?php

namespace Modules\Sviat\RozetkaDelivery\Backend;

require_once __DIR__ . '/../Support/SettingsWorld.php';
require_once __DIR__ . '/../Support/PartnerFixtures.php';

use Modules\Sviat\RozetkaDelivery\Support\PartnerFixtures;
use Modules\Sviat\RozetkaDelivery\Support\SettingsWorld;
use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Credentials;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyChecker;
use PHPUnit\Framework\TestCase;

class KeySettingsTest extends TestCase
{
    private SettingsWorld $world;

    protected function setUp(): void
    {
        $this->world = new SettingsWorld();
        $this->world->values = [Credentials::TOKEN => 'tok-123', Credentials::TOKEN_ID => 7];
    }

    private function issuePost(array $extra = []): array
    {
        return $extra + ['issue_phone' => '+38 (050) 111-22-33', 'issue_password' => 'pw-123'];
    }

    private function issueAnswers(int $id = 12, string $token = 'tok-new'): void
    {
        $http = $this->world->api->http;
        $http->json(201, ['statusCode' => 0, 'access_token' => 'jwt-abc', 'refresh_token' => 'jwt-r', 'status' => 'active']);
        $http->json(201, ['statusCode' => 0, 'data' => ['id' => $id, 'token' => $token]]);
        $http->json(200, ['statusCode' => 0]);
    }

    public function testEmptyFieldKeepsStoredToken(): void
    {
        $out = $this->world->keySettings()->save(['token' => '   ']);

        self::assertSame([], $out['errors']);
        self::assertSame('tok-123', $this->world->values[Credentials::TOKEN]);
        self::assertSame([], $this->world->api->http->requests());
    }

    public function testNewTokenVerifiedThenSaved(): void
    {
        $this->world->api->tokens($this->world->credentials)->rejected();
        $this->world->api->http->json(200, PartnerFixtures::verify());

        $out = $this->world->keySettings()->save(['token' => ' "tok-456" ']);

        self::assertSame([], $out['errors']);
        self::assertSame('tok-456', $this->world->values[Credentials::TOKEN]);
        self::assertSame('', $this->world->values[Credentials::TOKEN_ID], 'a pasted token is not the one the module issued');
        self::assertSame('Bearer tok-456', $this->world->api->http->requests()[0]->headers['Authorization']);
        self::assertNull($this->world->api->state->get('token_failed_at'));
        self::assertNotNull($this->world->checker()->partner());
    }

    public function testRejectedTokenNotSaved(): void
    {
        $this->world->api->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized']);

        $out = $this->world->keySettings()->save(['token' => 'tok-456']);

        self::assertSame(['token' => 'sviat__rozetka__err_token_rejected'], $out['errors']);
        self::assertSame('tok-123', $this->world->values[Credentials::TOKEN]);
        self::assertSame(7, $this->world->values[Credentials::TOKEN_ID]);
    }

    public function testBadCharsRejected(): void
    {
        foreach (["tok 456", "tok-456\r\nX-Evil: 1", "токен"] as $bad) {
            $out = $this->world->keySettings()->save(['token' => $bad]);
            self::assertSame(['token' => 'sviat__rozetka__err_token_chars'], $out['errors'], $bad);
        }
        self::assertSame('tok-123', $this->world->values[Credentials::TOKEN]);
        self::assertSame([], $this->world->api->http->requests());
    }

    public function testClearTokenWipesTokenAndId(): void
    {
        $this->world->api->flags->record(AuthFlags::TOKEN, RozetkaApiException::of(ApiErrorKind::BadToken, 'x'));
        $this->world->api->state->set(KeyChecker::PARTNER_STATE, '{"status":"active","name":"x"}');

        $this->world->keySettings()->save(['token' => '', 'clear_token' => '1']);

        self::assertSame('', $this->world->values[Credentials::TOKEN]);
        self::assertSame('', $this->world->values[Credentials::TOKEN_ID]);
        self::assertSame([], $this->world->api->flags->items());
        self::assertNull($this->world->api->state->get(KeyChecker::PARTNER_STATE));
    }

    public function testIssueSavesTokenAndId(): void
    {
        $this->issueAnswers();
        $this->world->api->http->json(200, PartnerFixtures::verify());

        $out = $this->world->keySettings()->issue($this->issuePost());

        self::assertSame([], $out['errors']);
        self::assertNull($out['warning']);
        self::assertSame('tok-new', $this->world->values[Credentials::TOKEN]);
        self::assertSame(12, $this->world->values[Credentials::TOKEN_ID]);
        self::assertSame(['POST auth/login', 'POST partner/static-token', 'DELETE auth/logout', 'GET auth/verify'], $this->world->calls());
        self::assertSame(['phone' => '380501112233', 'password' => 'pw-123'], json_decode((string)$this->world->api->http->requests()[0]->body, true));
        self::assertSame('Bearer tok-new', $this->world->api->http->requests()[3]->headers['Authorization']);
    }

    public function testIssueRevokesPreviousOnlyWhenAsked(): void
    {
        $this->world->api->http->json(201, ['statusCode' => 0, 'access_token' => 'jwt-abc', 'status' => 'active']);
        $this->world->api->http->json(201, ['statusCode' => 0, 'data' => ['id' => 12, 'token' => 'tok-new']]);
        $this->world->api->http->json(200, PartnerFixtures::verify());
        $this->world->api->http->json(200, ['statusCode' => 0]);
        $this->world->api->http->json(200, ['statusCode' => 0]);
        $this->world->api->http->json(200, PartnerFixtures::verify());

        $this->world->keySettings()->issue($this->issuePost(['revoke_previous' => '1']));

        self::assertSame(['POST auth/login', 'POST partner/static-token', 'GET auth/verify', 'DELETE partner/static-token/7', 'DELETE auth/logout', 'GET auth/verify'], $this->world->calls());
    }

    public function testIssueFailureSavesNothing(): void
    {
        $this->world->api->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized']);

        $out = $this->world->keySettings()->issue($this->issuePost());

        self::assertSame(['issue' => 'sviat__rozetka__issue_err_credentials'], $out['errors']);
        self::assertSame(['sviat__rozetka__token' => 'tok-123', 'sviat__rozetka__token_id' => 7], $this->world->values);
    }

    public function testIssuedTokenThatFailsVerifyIsNotSaved(): void
    {
        $this->issueAnswers();
        $this->world->api->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized']);

        $out = $this->world->keySettings()->issue($this->issuePost());

        self::assertSame(['issue' => 'sviat__rozetka__issue_err_verify'], $out['errors']);
        self::assertSame('tok-123', $this->world->values[Credentials::TOKEN]);
    }

    /** Тимчасова недоступність перевірки не губить уже випущений токен: зберігається, як і вставлений вручну. */
    public function testIssuedTokenSavedWhenVerifyUnreachable(): void
    {
        $this->issueAnswers();
        $this->world->api->http->json(503, ['statusCode' => 503]);

        $out = $this->world->keySettings()->issue($this->issuePost());

        self::assertSame([], $out['errors']);
        self::assertSame('tok-new', $this->world->values[Credentials::TOKEN]);
        self::assertSame(12, $this->world->values[Credentials::TOKEN_ID]);
    }

    public function testIssueValidatesInputBeforeAnyRequest(): void
    {
        $out = $this->world->keySettings()->issue(['issue_phone' => '12345', 'issue_password' => '']);

        self::assertSame(['issue_phone' => 'sviat__rozetka__issue_err_phone', 'issue_password' => 'sviat__rozetka__issue_err_password'], $out['errors']);
        self::assertSame([], $this->world->api->http->requests());
    }

    public function testRevokeDeletesThenClears(): void
    {
        $this->world->api->tokens($this->world->credentials)->rejected();
        $this->world->api->http->json(200, ['statusCode' => 0]);

        $out = $this->world->keySettings()->revoke();

        self::assertSame([], $out['errors']);
        self::assertSame(['DELETE partner/static-token/7'], $this->world->calls());
        self::assertSame('Bearer tok-123', $this->world->api->http->requests()[0]->headers['Authorization'], 'revoke goes out despite the local pause');
        self::assertSame('', $this->world->values[Credentials::TOKEN]);
        self::assertSame('', $this->world->values[Credentials::TOKEN_ID]);
    }

    public function testRevokeFailureKeepsToken(): void
    {
        $this->world->api->http->json(500, ['statusCode' => 500]);

        $out = $this->world->keySettings()->revoke();

        self::assertSame(['revoke' => 'sviat__rozetka__revoke_err'], $out['errors']);
        self::assertSame('tok-123', $this->world->values[Credentials::TOKEN]);
    }

    public function testRevokeWithoutIdSendsNothing(): void
    {
        $this->world->values[Credentials::TOKEN_ID] = '';

        $out = $this->world->keySettings()->revoke();

        self::assertSame(['revoke' => 'sviat__rozetka__revoke_err_no_id'], $out['errors']);
        self::assertSame([], $this->world->api->http->requests());
        self::assertSame('tok-123', $this->world->values[Credentials::TOKEN]);
    }

    public function testRevokeOfAlreadyDeadTokenStillClears(): void
    {
        $this->world->api->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized']);

        $out = $this->world->keySettings()->revoke();

        self::assertSame([], $out['errors']);
        self::assertSame('', $this->world->values[Credentials::TOKEN]);
    }
}
