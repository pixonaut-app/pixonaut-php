<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Support;

use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Pixonaut\Sdk\Config;
use Pixonaut\Sdk\Credentials\Credential;
use Pixonaut\Sdk\Credentials\CredentialProvider;
use Pixonaut\Sdk\Credentials\StaticCredentials;
use Pixonaut\Sdk\Http\ArrayCatalogue;
use Pixonaut\Sdk\Http\Listener;
use Pixonaut\Sdk\Http\Operation;
use Pixonaut\Sdk\Pixonaut;
use Psr\Http\Message\RequestInterface;

/**
 * A `Pixonaut` client over a scripted PSR-18 client and hand-written
 * operations.
 */
final class Harness
{
    public const BASE_URL = 'https://api.test';

    public const INTEGRATION_KEY = 'ik_test_secret';

    /** Unix time the harness clock reads. */
    public const NOW = 1_700_000_000;

    public readonly Client $http;

    public readonly RecordingLogger $logger;

    public readonly RecordingListener $listener;

    public readonly Pixonaut $pixonaut;

    /** @var list<int> */
    public array $sleeps = [];

    /**
     * @param list<Credential>|null $preference
     */
    public function __construct(
        ?CredentialProvider $credentials = null,
        string $baseUrl = self::BASE_URL,
        ?string $origin = null,
        ?array $preference = null,
        ?Listener $listener = null,
    ) {
        $this->http = new Client();
        $this->logger = new RecordingLogger();
        $this->listener = new RecordingListener();
        $factory = new Psr17Factory();
        $config = new Config(
            baseUrl: $baseUrl,
            product: 'test-host/1.0',
            credentialPreference: $preference,
            origin: $origin,
            sleep: function (int $seconds): void {
                $this->sleeps[] = $seconds;
            },
            clock: static fn(): int => self::NOW,
        );
        $this->pixonaut = new Pixonaut(
            $config,
            $this->http,
            $factory,
            $factory,
            $credentials ?? new StaticCredentials(integrationKey: self::INTEGRATION_KEY),
            self::catalogue(),
            $this->logger,
            $listener ?? $this->listener,
        );
    }

    public static function catalogue(): ArrayCatalogue
    {
        return new ArrayCatalogue(
            [
                new Operation('designs.get', 'GET', '/v1/designs/{id}', [Credential::IntegrationKey, Credential::PublishableKey]),
                new Operation('designs.commit', 'POST', '/v1/designs/{id}/commit', [Credential::IntegrationKey], idempotent: true),
                new Operation('designs.update', 'PATCH', '/v1/designs/{id}', [Credential::IntegrationKey]),
                new Operation('templates.list', 'GET', '/v1/templates', [Credential::PublishableKey, Credential::IntegrationKey], anonymous: true),
                new Operation('apiKeys.create', 'POST', '/v1/api-keys', [Credential::SecretKey, Credential::IntegrationKey], idempotent: true, sensitive: true),
                new Operation('designs.proof.get', 'GET', '/v1/designs/{id}/proof', [Credential::IntegrationKey], accept: 'image/png'),
            ],
            ['not_found', 'unauthorized', 'rate_limited', 'idempotency_key_in_use', 'idempotency_key_reused', 'invalid_idempotency_key', 'bad_request'],
            [400, 401, 404, 408, 409, 422, 429, 500, 503],
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function answer(int $status, string $body = '{}', array $headers = []): void
    {
        $this->http->addResponse(new Response($status, ['Content-Type' => 'application/json'] + $headers, $body));
    }

    /**
     * @return list<RequestInterface>
     */
    public function sent(): array
    {
        return array_values($this->http->getRequests());
    }

    public function lastSent(): RequestInterface
    {
        $last = $this->http->getLastRequest();
        if (!$last instanceof RequestInterface) {
            throw new \LogicException('Nothing was sent.');
        }

        return $last;
    }
}
