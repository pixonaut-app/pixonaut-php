<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Resource\Support;

use PHPUnit\Framework\Assert;

/**
 * The pinned spec's operations and the examples it documents for them.
 */
final class SpecExamples
{
    private const SPEC = __DIR__ . '/../../../../spec/openapi.json';

    public const JSON = 'application/json';

    /** @var array<string, array<array-key, mixed>>|null */
    private static ?array $operations = null;

    /**
     * @return array<string, array<array-key, mixed>> Operation id → the operation, with its `path` and `method`.
     */
    public static function operations(): array
    {
        if (self::$operations === null) {
            $spec = json_decode((string) file_get_contents(self::SPEC), true, 512, JSON_THROW_ON_ERROR);
            self::$operations = [];
            foreach (self::map(self::map($spec)['paths'] ?? []) as $path => $item) {
                foreach (self::map($item) as $method => $operation) {
                    $operation = self::map($operation);
                    if (isset($operation['operationId'])) {
                        self::$operations[self::text($operation['operationId'])] = ['path' => $path, 'method' => $method] + $operation;
                    }
                }
            }
        }

        return self::$operations;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function operation(string $id): array
    {
        // Not an assertion: PHPUnit exports what it asserts on, and the spec is large.
        return self::operations()[$id] ?? Assert::fail('The spec has no operation ' . $id . '.');
    }

    /**
     * The first example of the operation's JSON request body.
     */
    public static function request(string $id): mixed
    {
        return self::example(self::map(self::map(self::map(self::operation($id)['requestBody'] ?? [])['content'] ?? [])[self::JSON] ?? []));
    }

    /**
     * The first example of the operation's JSON answer with that status.
     */
    public static function answer(string $id, int $status): mixed
    {
        return self::example(self::map(self::content($id, $status)[self::JSON] ?? []));
    }

    /**
     * The media types the operation answers with that status.
     *
     * @return array<array-key, mixed>
     */
    public static function content(string $id, int $status): array
    {
        return self::map(self::map(self::map(self::operation($id)['responses'] ?? [])[(string) $status] ?? [])['content'] ?? []);
    }

    /**
     * @param array<array-key, mixed> $content
     */
    private static function example(array $content): mixed
    {
        if (array_key_exists('example', $content)) {
            return $content['example'];
        }
        $examples = self::map($content['examples'] ?? []);
        Assert::assertNotSame([], $examples, 'The spec has an example.');

        return self::map(reset($examples))['value'] ?? null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
