<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Http;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\ApiException;
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
}
