<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Pixonaut\Sdk\Config;
use Pixonaut\Sdk\Credentials\StaticCredentials;
use Pixonaut\Sdk\Generated\Operations;
use Pixonaut\Sdk\Http\Listener;
use Pixonaut\Sdk\Pixonaut;
use Pixonaut\Sdk\Tests\Fake\FakePixonaut;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * A `Pixonaut` client with the generated catalogue, holding a credential
 * of every kind, over the fake (or another PSR-18 client).
 */
final class ContractClient
{
    /** A reserved name: nothing outside the test resolves it. */
    public const BASE_URL = 'https://fake-pixonaut.invalid';

    public const INTEGRATION_KEY = 'ik_contract';

    public const SECRET_KEY = 'sk_contract';

    public const PUBLISHABLE_KEY = 'pk_contract';

    public const SHARE_LINK = 'link_contract';

    public const MEMBER_KEY = 'mk_contract';

    public readonly Pixonaut $pixonaut;

    /** @var list<int> Each wait the client asked for, in seconds. */
    public array $slept = [];

    public function __construct(
        public readonly ClientInterface $http = new FakePixonaut(),
        string $baseUrl = self::BASE_URL,
        ?LoggerInterface $logger = null,
        ?Listener $listener = null,
    ) {
        $factory = new Psr17Factory();
        $this->pixonaut = new Pixonaut(
            new Config(baseUrl: $baseUrl, product: 'contract-test/1.0', sleep: function (int $seconds): void {
                $this->slept[] = $seconds;
            }),
            $http,
            $factory,
            $factory,
            new StaticCredentials(self::INTEGRATION_KEY, self::SECRET_KEY, self::PUBLISHABLE_KEY, self::SHARE_LINK, self::MEMBER_KEY),
            Operations::catalogue(),
            $logger,
            $listener,
        );
    }

    public function fake(): FakePixonaut
    {
        return $this->http instanceof FakePixonaut ? $this->http : throw new \LogicException('The client is not over the in-memory fake.');
    }
}
