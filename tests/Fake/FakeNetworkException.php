<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Fake;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * The request got no answer, as a dropped connection would leave it.
 */
final class FakeNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
