<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Api;

/** Підсумок форми випуску токена: лише новий токен, його номер і мовні ключі; JWT і текстів Rozetka тут немає. */
final readonly class IssueResult
{
    public function __construct(
        public ?string $token = null,
        public ?int $id = null,
        public ?string $errorKey = null,
        public ?string $warningKey = null,
    ) {
    }

    public static function error(string $key): self
    {
        return new self(errorKey: $key);
    }
}
