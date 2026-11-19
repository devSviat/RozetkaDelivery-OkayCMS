<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

use Okay\Modules\Sviat\RozetkaDelivery\Api\Credentials;
use Okay\Modules\Sviat\RozetkaDelivery\Api\CredentialsProvider;

final class FixedCredentials extends CredentialsProvider
{
    public function __construct(private readonly Credentials $credentials)
    {
    }

    public function get(): Credentials
    {
        return $this->credentials;
    }
}
