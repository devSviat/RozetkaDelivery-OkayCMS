<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

require_once __DIR__ . '/ApiWorld.php';

use Okay\Core\Settings;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Credentials;
use Okay\Modules\Sviat\RozetkaDelivery\Api\CredentialsProvider;
use Okay\Modules\Sviat\RozetkaDelivery\Api\TokenIssuer;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\KeySettings;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\KeySettingsView;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\KeyChecker;

/** Налаштування ядра в масиві й справжні класи доступу поверх фейкового транспорту. */
final class SettingsWorld
{
    public ApiWorld $api;
    /** @var array<string, mixed> */
    public array $values = [];
    public Settings $settings;
    public CredentialsProvider $credentials;

    public function __construct()
    {
        $this->api = new ApiWorld();
        $values = &$this->values;
        $this->settings = new class ($values) extends Settings {
            public function __construct(private array &$values)
            {
            }

            public function get($param)
            {
                return $this->values[$param] ?? null;
            }

            public function set($param, $value)
            {
                $this->values[$param] = $value;
            }
        };
        $settings = $this->settings;
        $this->credentials = new class ($settings) extends CredentialsProvider {
            public function __construct(private Settings $settings)
            {
            }

            public function get(): Credentials
            {
                return Credentials::fromSettings($this->settings->get(...));
            }
        };
    }

    public function checker(): KeyChecker
    {
        $client = $this->api->client($this->credentials);

        return new KeyChecker($client, $this->api->tokens($this->credentials), $this->credentials, $this->api->state, $this->api->flags, $this->api->clock());
    }

    public function keySettings(): KeySettings
    {
        $client = $this->api->client($this->credentials);

        return new KeySettings(
            $this->settings,
            $this->api->tokens($this->credentials),
            $this->api->state,
            $this->api->flags,
            $this->checker(),
            new TokenIssuer($client, $this->api->state, $this->api->clock()),
            $client,
            $this->credentials,
        );
    }

    public function keyView(): KeySettingsView
    {
        return new KeySettingsView($this->credentials, $this->checker(), $this->api->flags, $this->api->clock());
    }

    /** @return list<string> */
    public function calls(): array
    {
        return array_map(
            static fn($r): string => $r->method . ' ' . substr($r->url, strlen(Credentials::BASE)),
            $this->api->http->requests(),
        );
    }
}
