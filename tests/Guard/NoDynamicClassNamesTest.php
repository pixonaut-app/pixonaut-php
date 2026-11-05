<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Guard;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps every class name in `src/` visible to Strauss and PHP-Scoper, so the
 * package still works after an integration prefixes it.
 */
final class NoDynamicClassNamesTest extends TestCase
{
    private const SOURCE_DIRECTORY = __DIR__ . '/../../src';

    private const FIXTURES = __DIR__ . '/fixtures';

    public function testSourceNamesEveryClassStatically(): void
    {
        $violations = (new DynamicClassNameFinder())->findInDirectory(self::SOURCE_DIRECTORY);

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function badFixtures(): iterable
    {
        yield 'new with a variable' => ['new-variable.php', ['class named by an expression']];
        yield 'static call on a variable' => ['static-call.php', ['class named by an expression']];
        yield 'constant on a variable' => ['class-constant.php', ['class named by an expression']];
        yield 'instanceof a variable' => ['instanceof.php', ['class named by an expression']];
        yield 'class checks with a string' => ['class-checks.php', [
            'class_exists() with a class name that is not a ::class literal',
            'is_a() with a class name that is not a ::class literal',
            'is_subclass_of() with a class name that is not a ::class literal',
            'is_a() with a class name that is not a ::class literal',
        ]];
        yield 'callables naming a class at run time' => ['string-callable.php', [
            'call_user_func() with a callable that does not name its class statically',
            'call_user_func_array() with a callable that does not name its class statically',
            'call_user_func() with a callable that does not name its class statically',
            'call_user_func() with a callable that does not name its class statically',
            'call_user_func_array() with a callable that does not name its class statically',
        ]];
        yield 'package class in a string' => ['class-in-string.php', [
            'class name in a string: Pixonaut\Sdk\Client',
            'class name in a string: \Pixonaut\Sdk\Model\Order',
        ]];
    }

    /**
     * @param list<string> $expectedProblems
     */
    #[DataProvider('badFixtures')]
    public function testRefusesFixture(string $fixture, array $expectedProblems): void
    {
        $path = self::FIXTURES . '/' . $fixture;

        $problems = array_map(
            static fn(string $violation): string => explode(': ', $violation, 2)[1] ?? $violation,
            (new DynamicClassNameFinder())->findInFile($path),
        );

        self::assertSame($expectedProblems, $problems);
    }

    public function testAcceptsStaticClassNames(): void
    {
        $violations = (new DynamicClassNameFinder())->findInFile(self::FIXTURES . '/allowed.php');

        self::assertSame([], $violations, implode("\n", $violations));
    }
}
