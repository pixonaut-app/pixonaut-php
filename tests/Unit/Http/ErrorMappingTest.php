<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Http;

use Http\Client\Exception\NetworkException;
use Http\Message\RequestMatcher\CallbackRequestMatcher;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Credentials\StaticCredentials;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Exception\IdempotencyBugException;
use Pixonaut\Sdk\Tests\Unit\Support\Harness;
use Psr\Http\Message\RequestInterface;

final class ErrorMappingTest extends TestCase
{
    /** Bytes of a non-JSON body an error keeps. */
    private const EXCERPT_BYTES = 500;

    /**
     * @param array<string, string> $headers
     */
    private static function refusal(int $status, string $body, array $headers = [], string $operation = 'designs.get'): ApiException
    {
        $harness = new Harness(new StaticCredentials(integrationKey: 'ik_1', secretKey: 'sk_1'));
        $harness->answer($status, $body, $headers);
        try {
            $operation === 'designs.get'
                ? $harness->pixonaut->call('designs.get', ['id' => 'd_1'])
                : $harness->pixonaut->call($operation, [], [], []);
        } catch (ApiException $e) {
            return $e;
        }
        self::fail('Expected an ApiException.');
    }

    public function testCarriesTheBodysCodeDetailsAndRequestId(): void
    {
        $e = self::refusal(404, '{"error":"not_found","message":"No design d_1.","field":"id"}', ['X-Request-Id' => 'req_1']);

        self::assertSame(ApiException::class, $e::class);
        self::assertSame('designs.get', $e->operation);
        self::assertSame(404, $e->status);
        self::assertSame('not_found', $e->errorCode);
        self::assertFalse($e->retryable);
        self::assertNull($e->retryAfter);
        self::assertSame('req_1', $e->requestId);
        self::assertSame(['message' => 'No design d_1.', 'field' => 'id'], $e->details);
        self::assertSame('Pixonaut designs.get answered 404 not_found', $e->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnError(): iterable
    {
        yield 'HTML' => ['<html>' . str_repeat('x', 600)];
        yield 'list' => ['["error"]'];
        yield 'error not a string' => ['{"error":5}'];
        yield 'empty' => [''];
    }

    #[DataProvider('notAnError')]
    public function testABodyThatIsNotAnErrorIsAnUnexpectedResponse(string $body): void
    {
        $e = self::refusal(502, $body);

        self::assertSame(ApiException::UNEXPECTED_RESPONSE, $e->errorCode);
        self::assertSame(['body' => substr($body, 0, self::EXCERPT_BYTES)], $e->details);
        self::assertTrue($e->retryable);
    }

    /**
     * @return iterable<string, array{int, string, bool}>
     */
    public static function retryability(): iterable
    {
        yield '500' => [500, '{"error":"db_error"}', true];
        yield '503' => [503, '{"error":"unavailable"}', true];
        yield '408' => [408, '{"error":"timeout"}', true];
        yield '429' => [429, '{"error":"rate_limited"}', true];
        yield '400' => [400, '{"error":"bad_request"}', false];
        yield '422' => [422, '{"error":"constraint_violation"}', false];
        yield 'body says retryable' => [422, '{"error":"bad_request","retryable":true}', true];
        yield 'body says not retryable' => [503, '{"error":"unavailable","retryable":false}', false];
        yield 'body retryable not a boolean' => [400, '{"error":"bad_request","retryable":"yes"}', false];
    }

    #[DataProvider('retryability')]
    public function testRetryable(int $status, string $body, bool $retryable): void
    {
        self::assertSame($retryable, self::refusal($status, $body)->retryable);
    }

    public function testInUseIsRetryableWhateverTheStatus(): void
    {
        // A GET takes no key, so it is not resent.
        self::assertTrue(self::refusal(409, '{"error":"idempotency_key_in_use"}')->retryable);
    }

    public function testRetryAfterInSeconds(): void
    {
        self::assertSame(30, self::refusal(429, '{"error":"rate_limited"}', ['Retry-After' => '30'])->retryAfter);
    }

    public function testRetryAfterAsAnHttpDate(): void
    {
        $in90Seconds = gmdate('D, d M Y H:i:s \G\M\T', Harness::NOW + 90);

        self::assertSame(90, self::refusal(429, '{"error":"rate_limited"}', ['Retry-After' => $in90Seconds])->retryAfter);
    }

    public function testRetryAfterInThePastIsZeroAndGarbageIsNull(): void
    {
        $past = gmdate('D, d M Y H:i:s \G\M\T', Harness::NOW - 90);

        self::assertSame(0, self::refusal(429, '{"error":"rate_limited"}', ['Retry-After' => $past])->retryAfter);
        self::assertNull(self::refusal(429, '{"error":"rate_limited"}', ['Retry-After' => 'soon'])->retryAfter);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idempotencyBugs(): iterable
    {
        yield 'reused' => ['idempotency_key_reused'];
        yield 'invalid' => ['invalid_idempotency_key'];
    }

    #[DataProvider('idempotencyBugs')]
    public function testAnIdempotencyBugIsNeverRetryable(string $code): void
    {
        $e = self::refusal(422, sprintf('{"error":"%s","retryable":true}', $code));

        self::assertInstanceOf(IdempotencyBugException::class, $e);
        self::assertSame($code, $e->errorCode);
        self::assertFalse($e->retryable);
    }

    public function testATransportFailureIsRetryableWithStatusZero(): void
    {
        $harness = new Harness();
        $failure = new NetworkException('Connection refused', new Request('GET', '/'));
        $harness->http->addException($failure);

        try {
            $harness->pixonaut->call('designs.get', ['id' => 'd_1']);
            self::fail('Expected transport_failed.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::TRANSPORT_FAILED, $e->errorCode);
            self::assertSame(0, $e->status);
            self::assertTrue($e->retryable);
            self::assertSame($failure, $e->getPrevious());
        }
        self::assertSame(['before designs.get', 'after designs.get'], $harness->listener->calls);
        self::assertSame([], $harness->logger->records);
    }

    public function testTheListenerNeverSeesTheRequestATransportFailureCarries(): void
    {
        $harness = new Harness();
        $harness->http->on(
            new CallbackRequestMatcher(static fn(): bool => true),
            static function (RequestInterface $request): never {
                throw new NetworkException('Connection refused', $request);
            },
        );

        try {
            $harness->pixonaut->call('designs.get', ['id' => 'd_1']);
            self::fail('Expected transport_failed.');
        } catch (ApiException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(NetworkException::class, $previous);
            self::assertSame('Bearer ' . Harness::INTEGRATION_KEY, $previous->getRequest()->getHeaderLine('Authorization'));
        }
        $seen = $harness->listener->after[0][2];
        self::assertInstanceOf(ApiException::class, $seen);
        self::assertSame(ApiException::TRANSPORT_FAILED, $seen->errorCode);
        self::assertNull($seen->getPrevious());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function undecodableJson(): iterable
    {
        yield 'HTML' => ['<html>busy</html>'];
        yield 'truncated' => ['{"id":'];
    }

    #[DataProvider('undecodableJson')]
    public function testASuccessThatIsNotJsonIsAnUnexpectedResponse(string $body): void
    {
        $harness = new Harness();
        $harness->answer(200, $body);
        $request = $harness->pixonaut->prepare('designs.get', ['id' => 'd_1']);

        foreach (['send', 'handle'] as $path) {
            try {
                $path === 'send'
                    ? $harness->pixonaut->send($request)
                    : $harness->pixonaut->handle($request, new \Nyholm\Psr7\Response(200, [], $body));
                self::fail('Expected unexpected_response.');
            } catch (ApiException $e) {
                self::assertSame(ApiException::UNEXPECTED_RESPONSE, $e->errorCode);
                self::assertSame(200, $e->status);
            }
        }
        self::assertNull($harness->listener->after[0][1]);
        self::assertSame(ApiException::UNEXPECTED_RESPONSE, $harness->listener->after[0][2]?->errorCode);
    }

    public function testAnEmptySuccessIsAResult(): void
    {
        $harness = new Harness();
        $harness->answer(204, '');

        self::assertSame(204, $harness->pixonaut->call('designs.get', ['id' => 'd_1'])->status);
    }

    public function testASensitiveOperationKeepsNoDetails(): void
    {
        $e = self::refusal(422, '{"error":"bad_request","key":"sk_leaked"}', ['X-Request-Id' => 'req_9'], 'apiKeys.create');

        self::assertSame('bad_request', $e->errorCode);
        self::assertSame(['redacted' => true], $e->details);
        self::assertSame('req_9', $e->requestId);
    }

    public function testASensitiveOperationsUnexpectedAnswerKeepsNoDetails(): void
    {
        $harness = new Harness(new StaticCredentials(secretKey: 'sk_1'));
        $harness->answer(201, '["sk_leaked"]');

        try {
            $harness->pixonaut->call('apiKeys.create', [], [], [])->object();
            self::fail('Expected unexpected_response.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::UNEXPECTED_RESPONSE, $e->errorCode);
            self::assertSame(['redacted' => true], $e->details);
        }
    }

    public function testAnUnknownCodeIsLoggedOnceAndStillThrown(): void
    {
        $harness = new Harness();
        $harness->answer(422, '{"error":"brand_new_refusal"}');

        try {
            $harness->pixonaut->call('designs.get', ['id' => 'd_1']);
            self::fail('Expected the refusal.');
        } catch (ApiException $e) {
            self::assertSame('brand_new_refusal', $e->errorCode);
        }
        $errors = $harness->logger->at('error');
        self::assertCount(1, $errors);
        self::assertSame('brand_new_refusal', $errors[0]['context']['code']);
        self::assertStringNotContainsString(Harness::INTEGRATION_KEY, (string) json_encode($harness->logger->records));
    }

    public function testAnUnknownStatusIsLogged(): void
    {
        $harness = new Harness();
        $harness->answer(418, '{"error":"not_found"}');

        try {
            $harness->pixonaut->call('designs.get', ['id' => 'd_1']);
        } catch (ApiException) {
        }

        self::assertCount(1, $harness->logger->at('error'));
    }

    public function testAKnownRefusalIsNotLogged(): void
    {
        $harness = new Harness();
        $harness->answer(404, '{"error":"not_found"}');

        try {
            $harness->pixonaut->call('designs.get', ['id' => 'd_1']);
        } catch (ApiException) {
        }

        self::assertSame([], $harness->logger->records);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function unauthorized(): iterable
    {
        yield '401' => [401, '{"error":"key_revoked"}'];
        yield 'unauthorized code' => [403, '{"error":"unauthorized"}'];
    }

    #[DataProvider('unauthorized')]
    public function testTellsTheListenerWhenTheCredentialIsRefused(int $status, string $body): void
    {
        $harness = new Harness();
        $harness->answer($status, $body);

        try {
            $harness->pixonaut->call('designs.get', ['id' => 'd_1']);
        } catch (ApiException) {
        }

        self::assertCount(1, $harness->listener->unauthorized);
        self::assertSame('unauthorized designs.get', $harness->listener->calls[2]);
    }

    public function testASuccessThatIsNotTheExpectedJsonIsAnUnexpectedResponse(): void
    {
        $harness = new Harness();
        $harness->answer(200, '[]', ['X-Request-Id' => 'req_2']);

        $result = $harness->pixonaut->call('designs.get', ['id' => 'd_1']);

        try {
            $result->object();
            self::fail('Expected unexpected_response.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::UNEXPECTED_RESPONSE, $e->errorCode);
            self::assertSame(200, $e->status);
            self::assertSame('req_2', $e->requestId);
            self::assertSame(['body' => '[]'], $e->details);
            self::assertFalse($e->retryable);
        }
    }
}
