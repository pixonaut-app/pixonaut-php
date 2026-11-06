<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\Sdk\Model\Decode;

final class DecodeTest extends TestCase
{
    /**
     * @return iterable<string, array{float, int}>
     */
    public static function integralFloats(): iterable
    {
        yield 'small' => [2.0, 2];
        yield 'negative' => [-7.0, -7];
        yield 'lowest integer' => [(float) PHP_INT_MIN, PHP_INT_MIN];
    }

    #[DataProvider('integralFloats')]
    public function testAnIntegralFloatIsAnInteger(float $value, int $expected): void
    {
        self::assertSame($expected, Decode::int($value, '$.n'));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonIntegers(): iterable
    {
        yield 'fraction' => [1.5];
        // `(float) PHP_INT_MAX` is 2^63, one past the largest integer.
        yield 'past the largest integer' => [(float) PHP_INT_MAX];
        yield 'far past the largest integer' => [1.0e30];
        yield 'far below the lowest integer' => [-1.0e30];
    }

    #[DataProvider('nonIntegers')]
    public function testAFloatThatIsNoIntegerIsRefused(float $value): void
    {
        $this->expectExceptionObject(new UnexpectedResponse('$.n', 'expected integer, got number'));

        Decode::int($value, '$.n');
    }
}
