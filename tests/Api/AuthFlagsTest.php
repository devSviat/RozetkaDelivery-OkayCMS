<?php

namespace Modules\Sviat\RozetkaDelivery\Api;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\RozetkaDelivery\Support\InMemoryState;
use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use PHPUnit\Framework\TestCase;

class AuthFlagsTest extends TestCase
{
    public function testNothingFlaggedNothingShown(): void
    {
        self::assertSame([], new AuthFlags(new InMemoryState())->items());
    }

    public function testStoresKindNotApiText(): void
    {
        $state = new InMemoryState();
        $flags = new AuthFlags($state);

        self::assertTrue($flags->record(AuthFlags::TOKEN, RozetkaApiException::of(ApiErrorKind::BadToken, 'x', null, 'Unauthorized tok-123')));

        self::assertSame([['api' => 'token', 'reason' => 'bad_token']], $flags->items());
        self::assertStringNotContainsString('Unauthorized', json_encode([$flags->items(), $state->values], JSON_UNESCAPED_UNICODE));
    }

    public function testOnlyAuthRefusalsAreRecorded(): void
    {
        $flags = new AuthFlags(new InMemoryState());

        self::assertFalse($flags->record(AuthFlags::TOKEN, RozetkaApiException::of(ApiErrorKind::Rejected, 'x')));
        self::assertFalse($flags->record(AuthFlags::TOKEN, RozetkaApiException::of(ApiErrorKind::NotConfigured, 'x')));
        self::assertFalse($flags->record(AuthFlags::TRACKING, RozetkaApiException::of(ApiErrorKind::RateLimited, 'x')));
        self::assertSame([], $flags->items());
    }

    public function testFlagsAreIndependentAndClearable(): void
    {
        $flags = new AuthFlags(new InMemoryState());
        $flags->record(AuthFlags::TOKEN, RozetkaApiException::of(ApiErrorKind::BadToken, 'x'));
        $flags->record(AuthFlags::TRACKING, RozetkaApiException::of(ApiErrorKind::BadToken, 'x'));

        $flags->clear(AuthFlags::TRACKING);

        self::assertSame([['api' => 'token', 'reason' => 'bad_token']], $flags->items());
        self::assertNull($flags->reason(AuthFlags::TRACKING));
    }

    public function testGarbageInStateIsIgnored(): void
    {
        $state = new InMemoryState();
        $state->set('token_auth_failed', '<b>x</b>');
        $state->set('tracking_auth_failed', 'rejected');

        self::assertSame([], new AuthFlags($state)->items());
    }
}
