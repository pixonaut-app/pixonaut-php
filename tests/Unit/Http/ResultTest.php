<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Http\Result;

final class ResultTest extends TestCase
{
    public function testReadsHeadersCaseInsensitively(): void
    {
        $result = new Result('designs.get', 200, ['X-Request-ID' => 'req_1', 'Idempotent-Replayed' => 'true'], '{}');

        self::assertSame('req_1', $result->header('x-request-id'));
        self::assertSame('req_1', $result->requestId());
        self::assertTrue($result->replayed());
        self::assertNull($result->header('Missing'));
    }

    public function testIsNotReplayedWithoutTheHeader(): void
    {
        self::assertFalse((new Result('designs.get', 200, [], '{}'))->replayed());
        self::assertNull((new Result('designs.get', 200, [], '{}'))->requestId());
    }

    public function testDecodesAnObject(): void
    {
        self::assertSame(['a' => 1], (new Result('x', 200, [], '{"a":1}'))->object());
        self::assertSame([], (new Result('x', 200, [], '{}'))->object());
    }

    public function testDecodesAList(): void
    {
        self::assertSame([1, 2], (new Result('x', 200, [], '[1,2]'))->list());
        self::assertSame([], (new Result('x', 200, [], '[]'))->list());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notObjects(): iterable
    {
        yield 'empty list' => ['[]'];
        yield 'list' => ['[{"a":1}]'];
        yield 'string' => ['"a"'];
        yield 'not JSON' => ['nope'];
        yield 'empty' => [''];
    }

    #[DataProvider('notObjects')]
    public function testRefusesWhatIsNotAnObject(string $body): void
    {
        $this->expectException(ApiException::class);

        (new Result('x', 200, [], $body))->object();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notLists(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'object' => ['{"0":"a"}'];
        yield 'number' => ['1'];
        yield 'not JSON' => ['['];
    }

    #[DataProvider('notLists')]
    public function testRefusesWhatIsNotAList(string $body): void
    {
        $this->expectException(ApiException::class);

        (new Result('x', 200, [], $body))->list();
    }
}
