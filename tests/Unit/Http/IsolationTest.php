<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Credentials\StaticCredentials;
use Pixonaut\Sdk\Tests\Unit\Support\Harness;

final class IsolationTest extends TestCase
{
    public function testTwoClientsWithDifferentKeysAndHostsShareNothing(): void
    {
        $one = new Harness(new StaticCredentials(integrationKey: 'ik_one'), 'https://one.test/');
        $two = new Harness(new StaticCredentials(integrationKey: 'ik_two'), 'http://two.test:8080/base');
        $one->answer(200);
        $two->answer(200);
        $one->answer(200);

        $one->pixonaut->call('designs.get', ['id' => 'd_1']);
        $two->pixonaut->call('designs.get', ['id' => 'd_2']);
        $one->pixonaut->call('designs.get', ['id' => 'd_3']);

        self::assertSame(
            ['https://one.test/v1/designs/d_1', 'https://one.test/v1/designs/d_3'],
            array_map(static fn($r): string => (string) $r->getUri(), $one->sent()),
        );
        self::assertSame('http://two.test:8080/base/v1/designs/d_2', (string) $two->lastSent()->getUri());
        self::assertSame('Bearer ik_one', $one->lastSent()->getHeaderLine('Authorization'));
        self::assertSame('Bearer ik_two', $two->lastSent()->getHeaderLine('Authorization'));
        self::assertCount(2, $one->listener->after);
        self::assertCount(1, $two->listener->after);
    }
}
