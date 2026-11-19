<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

require_once __DIR__ . '/FakeTransport.php';
require_once __DIR__ . '/InMemoryState.php';
require_once __DIR__ . '/InMemoryMutex.php';
require_once __DIR__ . '/FixedCredentials.php';
require_once __DIR__ . '/ArrayLogger.php';

use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Credentials;
use Okay\Modules\Sviat\RozetkaDelivery\Api\CredentialsProvider;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RequestBudget;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Api\TokenProvider;

/** Клієнт API на фейковому транспорті з одним станом і годинником, який тест посуває сам. */
final class ApiWorld
{
    public const int START = 1_800_000_000;

    public FakeTransport $http;
    public InMemoryState $state;
    public ArrayLogger $logger;
    public AuthFlags $flags;
    public int $now = self::START;

    public function __construct()
    {
        $this->http = new FakeTransport();
        $this->state = new InMemoryState();
        $this->logger = new ArrayLogger();
        $this->flags = new AuthFlags($this->state);
    }

    /** @return \Closure(): int */
    public function clock(): \Closure
    {
        return fn(): int => $this->now;
    }

    public function credentials(string $token = 'tok-123', ?int $tokenId = 7): FixedCredentials
    {
        return new FixedCredentials(new Credentials($token, $tokenId));
    }

    public function tokens(?CredentialsProvider $credentials = null): TokenProvider
    {
        return new TokenProvider($credentials ?? $this->credentials(), $this->state, $this->flags, $this->clock());
    }

    /** Бюджет має окремий стан і годинник, що йде на секунду за запит: тести перевіряють не ліміт, а сценарії. */
    public function client(?CredentialsProvider $credentials = null): RozetkaClient
    {
        $credentials ??= $this->credentials();
        $tick = 0;
        $budget = new RequestBudget(new InMemoryState(), new InMemoryMutex(), static function () use (&$tick): int {
            return $tick++;
        });

        return new RozetkaClient($this->http, $this->tokens($credentials), $budget, $credentials, $this->logger);
    }
}
