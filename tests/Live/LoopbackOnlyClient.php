<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Live;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 client that sends only to loopback hosts and refuses any other
 * request before it is sent.
 */
final class LoopbackOnlyClient implements ClientInterface
{
    public function __construct(private readonly ClientInterface $client) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $host = $request->getUri()->getHost();
        if (!LiveTarget::isLoopback($host)) {
            throw new class ($request, 'The live smoke sends only to loopback, not ' . $host . '.') extends RuntimeException implements RequestExceptionInterface {
                public function __construct(private readonly RequestInterface $request, string $message)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        }

        return $this->client->sendRequest($request);
    }
}
