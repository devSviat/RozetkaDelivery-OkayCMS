<?php

namespace Modules\Sviat\RozetkaDelivery\KeyCheck;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/PartnerFixtures.php';

use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\FixedCredentials;
use Modules\Sviat\RozetkaDelivery\Support\PartnerFixtures;
use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Credentials;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\TransportException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyChecker;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyStatus;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\PartnerInfo;
use PHPUnit\Framework\TestCase;

class KeyCheckerTest extends TestCase
{
    private ApiWorld $world;

    protected function setUp(): void
    {
        $this->world = new ApiWorld();
    }

    private function checker(?FixedCredentials $credentials = null): KeyChecker
    {
        $credentials ??= $this->world->credentials();

        return new KeyChecker(
            $this->world->client($credentials),
            $this->world->tokens($credentials),
            $credentials,
            $this->world->state,
            $this->world->flags,
            $this->world->clock(),
        );
    }

    public function testCheckReadsVerify(): void
    {
        $this->world->http->json(200, PartnerFixtures::verify());

        $out = $this->checker()->check();

        $request = $this->world->http->requests()[0];
        self::assertSame('GET', $request->method);
        self::assertSame('https://rz-delivery.rozetka.ua/api/auth/verify', $request->url);
        self::assertSame('Bearer tok-123', $request->headers['Authorization']);
        self::assertSame(KeyStatus::Ok, $out['result']->auth);
        self::assertSame('ФОП Тестовий Продавець', $out['result']->partner?->name);
        self::assertSame('active', $out['result']->partner->status);
        self::assertSame('RD-0001', $out['result']->partner->contractNumber);
        self::assertSame('ФОП Тестовий Продавець', $this->checker()->partner()?->name);
        self::assertSame(KeyStatus::Ok, $this->checker()->lastResult()?->auth);
    }

    public function testVerifyStoresNoContactData(): void
    {
        $this->world->http->json(200, PartnerFixtures::verify());

        $this->checker()->check();

        $stored = json_encode($this->world->state->values, JSON_UNESCAPED_UNICODE);
        foreach ([PartnerFixtures::EMAIL, PartnerFixtures::PHONE, PartnerFixtures::CONTACT, PartnerFixtures::MERCHANT, 'tok-123'] as $private) {
            self::assertStringNotContainsString($private, $stored);
        }
        $encoded = PartnerInfo::fromVerify(PartnerFixtures::verify())->encode();
        foreach ([PartnerFixtures::EMAIL, PartnerFixtures::PHONE, PartnerFixtures::CONTACT, PartnerFixtures::MERCHANT] as $private) {
            self::assertStringNotContainsString($private, $encoded);
        }
        self::assertEquals(PartnerInfo::fromVerify(PartnerFixtures::verify()), PartnerInfo::decode($encoded));
    }

    public function testWarnings(): void
    {
        $now = new \DateTimeImmutable('2027-12-10 12:00:00', new \DateTimeZone('Europe/Kyiv'))->getTimestamp();

        self::assertSame([], PartnerInfo::fromVerify(PartnerFixtures::verify())->warnings($now - 60 * 86400));
        $all = PartnerInfo::fromVerify(PartnerFixtures::verify(['status' => 'blocked', 'autoblock_days' => 3]));
        self::assertSame(['sviat__rozetka__warn_partner_status', 'sviat__rozetka__warn_autoblock', 'sviat__rozetka__warn_contract_expires'], $all->warnings($now));
        self::assertSame([], PartnerInfo::fromVerify(PartnerFixtures::verify(['status' => 'verified', 'autoblock_days' => 4]))->warnings($now - 60 * 86400));
        self::assertSame([], PartnerInfo::fromVerify(PartnerFixtures::verify(['autoblock_days' => null, 'contract' => null]))->warnings($now));
    }

    public function testBadTokenFromProbe(): void
    {
        $this->world->http->json(401, ['statusCode' => 401, 'message' => 'Unauthorized']);

        $out = $this->checker()->probe(new Credentials('tok-456'));

        self::assertSame(KeyStatus::BadToken, $out['result']->auth);
        self::assertNull($out['result']->partner);
        self::assertSame('Bearer tok-456', $this->world->http->requests()[0]->headers['Authorization']);
        self::assertSame([], $this->world->state->values, 'probe writes nothing and does not pause the stored token');
    }

    public function testCheckIgnoresPauseAndClearsFlags(): void
    {
        $this->world->tokens()->rejected();
        $this->world->flags->record(AuthFlags::TRACKING, RozetkaApiException::of(ApiErrorKind::BadToken, 'x'));
        $this->world->http->json(200, PartnerFixtures::verify());

        self::assertSame(KeyStatus::Ok, $this->checker()->check()['result']->auth);
        self::assertSame([], $this->world->flags->items());
        self::assertNull($this->world->state->get('token_failed_at'));
    }

    public function testOtherOutcomes(): void
    {
        $this->world->http->json(403, ['statusCode' => 403, 'message' => 'Forbidden'])
            ->push(new TransportException('timeout'));

        $forbidden = $this->checker()->check();
        self::assertSame(KeyStatus::Rejected, $forbidden['result']->auth);
        self::assertSame('Forbidden', $forbidden['message']);
        self::assertStringNotContainsString('Forbidden', (string)$this->world->state->get(KeyChecker::STATE));
        self::assertSame(KeyStatus::Unreachable, $this->checker()->check()['result']->auth);
        self::assertSame(KeyStatus::NotConfigured, $this->checker($this->world->credentials(''))->check()['result']->auth);
        self::assertCount(2, $this->world->http->requests());
    }

    public function testGarbageStateIsIgnored(): void
    {
        $this->world->state->set(KeyChecker::STATE, '{"auth":"<b>"}');
        $this->world->state->set(KeyChecker::PARTNER_STATE, '[1,2]');

        self::assertNull($this->checker()->lastResult());
        self::assertNull($this->checker()->partner());
    }
}
