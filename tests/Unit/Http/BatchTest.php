<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Http;

use Http\Client\Exception\NetworkException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Http\ErrorMapper;
use Pixonaut\Sdk\Tests\Unit\Support\Harness;

final class BatchTest extends TestCase
{
    public function testAHostSendsPreparedRequestsItselfWithTheSameErrorMapping(): void
    {
        $harness = new Harness();
        $first = $harness->pixonaut->prepare('designs.get', ['id' => 'd_1']);
        $second = $harness->pixonaut->prepare('designs.get', ['id' => 'd_2']);

        $a = $harness->pixonaut->toPsrRequest($first);
        $b = $harness->pixonaut->toPsrRequest($second);

        self::assertSame('https://api.test/v1/designs/d_2', (string) $b->getUri());
        self::assertSame('Bearer ' . Harness::INTEGRATION_KEY, $a->getHeaderLine('Authorization'));

        $result = $harness->pixonaut->handle($first, new Response(200, [], '{"id":"d_1"}'));
        self::assertSame(['id' => 'd_1'], $result->object());

        try {
            $harness->pixonaut->handle($second, new Response(404, [], '{"error":"not_found"}'));
            self::fail('Expected not_found.');
        } catch (ApiException $e) {
            self::assertSame('not_found', $e->errorCode);
        }

        self::assertSame([], $harness->sent());
        self::assertSame(['before designs.get', 'before designs.get', 'after designs.get', 'after designs.get'], $harness->listener->calls);
    }

    public function testARequestThatGotNoResponseMapsToTheTransportFailureSendWouldThrow(): void
    {
        $harness = new Harness();
        $prepared = $harness->pixonaut->prepare('designs.get', ['id' => 'd_1']);
        $psr = $harness->pixonaut->toPsrRequest($prepared);
        $failure = new NetworkException('Connection refused', $psr);

        $error = $harness->pixonaut->handleFailure($prepared, $failure);

        self::assertSame(ApiException::TRANSPORT_FAILED, $error->errorCode);
        self::assertSame('designs.get', $error->operation);
        self::assertSame(0, $error->status);
        self::assertTrue($error->retryable);
        self::assertSame(['message' => 'Connection refused'], $error->details);
        self::assertSame($failure, $error->getPrevious());

        self::assertSame([], $harness->sent());
        self::assertSame(['before designs.get', 'after designs.get'], $harness->listener->calls);
        [, $result, $seen] = $harness->listener->after[0];
        self::assertNull($result);
        self::assertInstanceOf(ApiException::class, $seen);
        self::assertSame(ApiException::TRANSPORT_FAILED, $seen->errorCode);
        // The PSR-18 exception carries the request, credential included.
        self::assertNull($seen->getPrevious());
        self::assertSame([], $harness->logger->records);
    }

    public function testATransportFailureOfASensitiveOperationKeepsItsMessageOutOfTheDetails(): void
    {
        $harness = new Harness();
        $prepared = $harness->pixonaut->prepare('apiKeys.create', body: ['name' => 'k'], idempotencyKey: 'key-1');
        $psr = $harness->pixonaut->toPsrRequest($prepared);

        $error = $harness->pixonaut->handleFailure($prepared, new NetworkException('Connection refused', $psr));

        self::assertSame(ApiException::TRANSPORT_FAILED, $error->errorCode);
        self::assertSame(ErrorMapper::REDACTED, $error->details);
        self::assertSame(ErrorMapper::REDACTED, $harness->listener->after[0][2]?->details);
    }
}
