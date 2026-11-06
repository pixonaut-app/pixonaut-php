<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Resource\Support;

use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Pixonaut\Sdk\Config;
use Pixonaut\Sdk\Credentials\StaticCredentials;
use Pixonaut\Sdk\Generated\Operations;
use Pixonaut\Sdk\Pixonaut;
use Psr\Http\Message\RequestInterface;

/**
 * A `Pixonaut` client with the generated catalogue over a scripted PSR-18
 * client, holding every kind of credential so any operation can be sent.
 */
final class SpecClient
{
    public const BASE_URL = 'https://api.test';

    public readonly Client $http;

    public readonly Pixonaut $pixonaut;

    public function __construct()
    {
        $this->http = new Client();
        $factory = new Psr17Factory();
        $this->pixonaut = new Pixonaut(
            new Config(baseUrl: self::BASE_URL, product: 'test-host/1.0', sleep: static function (int $seconds): void {}),
            $this->http,
            $factory,
            $factory,
            new StaticCredentials('ik_test', 'sk_test', 'pk_test', 'sl_test', 'mk_test'),
            Operations::catalogue(),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function answer(int $status, string $body, array $headers = []): void
    {
        $this->http->addResponse(new Response($status, $headers, $body));
    }

    /**
     * @param mixed $json Encoded as the answer's body.
     */
    public function answerJson(int $status, mixed $json): void
    {
        $this->answer($status, json_encode($json, JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json', 'X-Request-Id' => 'req_1']);
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
