<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Pixonaut;
use Pixonaut\Sdk\Tests\Unit\Support\Harness;

final class IdempotencyResendTest extends TestCase
{
    private const IN_USE = '{"error":"idempotency_key_in_use"}';

    public function testResendsTheSameBytesAndKeyWhileTheKeyIsInUse(): void
    {
        $harness = new Harness();
        $harness->answer(409, self::IN_USE, ['Retry-After' => '2']);
        $harness->answer(201, '{"id":"d_1"}');

        $result = $harness->pixonaut->call('designs.commit', ['id' => 'd_1'], [], ['proof' => 'p_1']);

        self::assertSame(201, $result->status);
        [$first, $second] = $harness->sent();
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame($first->getHeaderLine('Idempotency-Key'), $second->getHeaderLine('Idempotency-Key'));
        self::assertSame([2], $harness->sleeps);
    }

    public function testWaitsOneSecondWithoutRetryAfterAndCapsALongWait(): void
    {
        $harness = new Harness();
        $harness->answer(409, self::IN_USE);
        $harness->answer(409, self::IN_USE, ['Retry-After' => '60']);
        $harness->answer(200);

        $harness->pixonaut->call('designs.commit', ['id' => 'd_1'], [], []);

        self::assertSame([1, 5], $harness->sleeps);
    }

    public function testThrowsAfterTheThirdResend(): void
    {
        $harness = new Harness();
        for ($i = 0; $i <= Pixonaut::MAX_IN_USE_RESENDS; ++$i) {
            $harness->answer(409, self::IN_USE);
        }

        try {
            $harness->pixonaut->call('designs.commit', ['id' => 'd_1'], [], []);
            self::fail('Expected idempotency_key_in_use.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::IDEMPOTENCY_KEY_IN_USE, $e->errorCode);
            self::assertSame(409, $e->status);
            self::assertTrue($e->retryable);
        }
        self::assertCount(Pixonaut::MAX_IN_USE_RESENDS + 1, $harness->sent());
        self::assertSame([1, 1, 1], $harness->sleeps);
    }

    public function testRetriesNothingElse(): void
    {
        $harness = new Harness();
        $harness->answer(503, '{"error":"unavailable"}', ['Retry-After' => '1']);

        try {
            $harness->pixonaut->call('designs.commit', ['id' => 'd_1'], [], []);
            self::fail('Expected the 503.');
        } catch (ApiException $e) {
            self::assertTrue($e->retryable);
        }
        self::assertCount(1, $harness->sent());
        self::assertSame([], $harness->sleeps);
    }

    public function testListenerSeesEachResendAndTheBodyOnce(): void
    {
        $harness = new Harness();
        $harness->answer(409, self::IN_USE);
        $harness->answer(200);

        $harness->pixonaut->call('designs.commit', ['id' => 'd_1'], [], ['a' => 1]);

        self::assertSame(
            ['body designs.commit', 'before designs.commit', 'after designs.commit', 'before designs.commit', 'after designs.commit'],
            $harness->listener->calls,
        );
        self::assertSame(ApiException::IDEMPOTENCY_KEY_IN_USE, $harness->listener->after[0][2]?->errorCode);
        self::assertSame(200, $harness->listener->after[1][1]?->status);
    }

    public function testResendsOnlyOnA409(): void
    {
        $harness = new Harness();
        $harness->answer(401, self::IN_USE);
        $harness->answer(200);

        try {
            $harness->pixonaut->call('designs.commit', ['id' => 'd_1'], [], []);
            self::fail('Expected the 401.');
        } catch (ApiException $e) {
            self::assertSame(401, $e->status);
        }
        self::assertCount(1, $harness->sent());
        self::assertSame([], $harness->sleeps);
        self::assertCount(1, $harness->listener->unauthorized);
    }
}
