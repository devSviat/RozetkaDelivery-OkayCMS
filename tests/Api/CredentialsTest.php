<?php

namespace Modules\Sviat\RozetkaDelivery\Api;

use Okay\Modules\Sviat\RozetkaDelivery\Api\Credentials;
use PHPUnit\Framework\TestCase;

class CredentialsTest extends TestCase
{
    /** @param array<string, mixed> $settings */
    private static function from(array $settings): Credentials
    {
        return Credentials::fromSettings(static fn(string $key): mixed => $settings[$key] ?? null);
    }

    public function testConfiguredByToken(): void
    {
        $credentials = self::from([Credentials::TOKEN => ' tok-123 ', Credentials::TOKEN_ID => '7']);

        self::assertTrue($credentials->configured());
        self::assertSame('tok-123', $credentials->token);
        self::assertSame(7, $credentials->tokenId);
        self::assertFalse(self::from([])->configured());
        self::assertNull(self::from([])->tokenId);
        self::assertFalse(self::from([Credentials::TOKEN => '   ', Credentials::TOKEN_ID => '7'])->configured());
        self::assertNull(self::from([Credentials::TOKEN_ID => 'abc'])->tokenId);
        self::assertNull(self::from([Credentials::TOKEN_ID => '0'])->tokenId);
    }

    public function testNormalizeStripsQuotesAndZeroWidth(): void
    {
        self::assertSame('tok-123', Credentials::normalizeToken("\u{FEFF} «tok-123»\u{200B}\n"));
        self::assertSame('tok-123', Credentials::normalizeToken('"tok-123"'));
        self::assertSame('a b', Credentials::normalizeToken(' a b '));
    }

    /** Номер токена лише називає його в кабінеті: маскувати його в текстах помилок не треба. */
    public function testSecretsListsOnlyToken(): void
    {
        self::assertSame(['tok-123'], self::from([Credentials::TOKEN => 'tok-123', Credentials::TOKEN_ID => '7'])->secrets());
        self::assertSame([], self::from([Credentials::TOKEN_ID => '7'])->secrets());
    }

    public function testBaseIsTheDocumentedHost(): void
    {
        self::assertSame('https://rz-delivery.rozetka.ua/api/', Credentials::BASE);
    }
}
