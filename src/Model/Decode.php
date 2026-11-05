<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Model;

use Closure;
use Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * The checks generated models decode with. Each takes the value and its JSON
 * path, and returns the value typed or throws `UnexpectedResponse` naming the
 * path.
 */
final class Decode
{
    // Callbacks are invoked through `__invoke()`, so that no call in the
    // package names its function by an expression a prefixer cannot see.

    /**
     * A JSON object: an array that is not a non-empty list. An empty array is
     * accepted, since `json_decode()` gives `{}` and `[]` alike.
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            self::fail($path, 'expected object, got ' . self::describe($value));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function required(array $data, string $key, string $path): mixed
    {
        if (!array_key_exists($key, $data)) {
            self::fail($path, sprintf('missing required field "%s"', $key));
        }

        return $data[$key];
    }

    public static function string(mixed $value, string $path): string
    {
        return is_string($value) ? $value : self::fail($path, 'expected string, got ' . self::describe($value));
    }

    /**
     * An integer; a float with no fractional part is accepted too, since a
     * JSON encoder may write `1.0`.
     */
    public static function int(mixed $value, string $path): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX) {
            return (int) $value;
        }

        return self::fail($path, 'expected integer, got ' . self::describe($value));
    }

    public static function float(mixed $value, string $path): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return self::fail($path, 'expected number, got ' . self::describe($value));
    }

    public static function bool(mixed $value, string $path): bool
    {
        return is_bool($value) ? $value : self::fail($path, 'expected boolean, got ' . self::describe($value));
    }

    /**
     * @return null
     */
    public static function null(mixed $value, string $path): mixed
    {
        return $value === null ? null : self::fail($path, 'expected null, got ' . self::describe($value));
    }

    /**
     * A field whose schema allows one value only: how a union's variant is
     * told apart.
     */
    public static function constant(mixed $value, string|int|float|bool $expected, string $path): mixed
    {
        if ($value === $expected || (is_int($value) && is_float($expected) && (float) $value === $expected)) {
            return $value;
        }

        return self::fail($path, sprintf('expected %s, got %s', json_encode($expected), self::describe($value)));
    }

    /**
     * @template T
     *
     * @param Closure(mixed, string): T $item
     *
     * @return T|null
     */
    public static function nullable(mixed $value, string $path, Closure $item): mixed
    {
        return $value === null ? null : $item->__invoke($value, $path);
    }

    /**
     * @template T
     *
     * @param Closure(mixed, string): T $item
     *
     * @return list<T>
     */
    public static function list(mixed $value, string $path, Closure $item): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            self::fail($path, 'expected array, got ' . self::describe($value));
        }
        $items = [];
        foreach ($value as $index => $element) {
            $items[] = $item->__invoke($element, $path . '[' . $index . ']');
        }

        return $items;
    }

    /**
     * An array whose items each have their own schema (`prefixItems`).
     *
     * @template T
     *
     * @param list<Closure(mixed, string): T> $items
     * @param (Closure(mixed, string): T)|null $rest Items past the listed ones; none allowed when null.
     *
     * @return list<T>
     */
    public static function tuple(mixed $value, string $path, array $items, ?Closure $rest = null): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            self::fail($path, 'expected array, got ' . self::describe($value));
        }
        $decoded = [];
        foreach ($value as $index => $element) {
            $decode = $items[$index] ?? $rest ?? self::fail($path, sprintf('expected at most %d items', count($items)));
            $decoded[] = $decode->__invoke($element, $path . '[' . $index . ']');
        }

        return $decoded;
    }

    /**
     * A JSON object used as a map from names to values of one schema.
     *
     * @template T
     *
     * @param Closure(mixed, string): T $item
     *
     * @return array<string, T>
     */
    public static function map(mixed $value, string $path, Closure $item): array
    {
        $map = [];
        foreach (self::object($value, $path) as $key => $element) {
            $map[(string) $key] = $item->__invoke($element, self::field($path, (string) $key));
        }

        return $map;
    }

    /**
     * The value of the field that names an object's variant.
     */
    public static function discriminator(mixed $value, string $path, string $property): string
    {
        return self::string(self::required(self::object($value, $path), $property, $path), self::field($path, $property));
    }

    /**
     * @throws UnexpectedResponse
     */
    public static function unknownVariant(string $path, string $property, string $value): never
    {
        self::fail(self::field($path, $property), sprintf('unknown %s "%s"', $property, $value));
    }

    /**
     * The value decoded by the one variant it matches (`oneOf`).
     *
     * Variants come most specific first: a variant whose fields allow one
     * value only, or that requires more fields, is tried before one that
     * would also accept the value but says less about it. Each variant comes
     * with its specificity; matching two variants of the same specificity is
     * an error, matching a more and a less specific one picks the former.
     *
     * @template T
     *
     * @param list<array{Closure(mixed, string): T, int}> $variants
     *
     * @return T
     */
    public static function oneOf(mixed $value, string $path, array $variants): mixed
    {
        $problems = [];
        foreach ($variants as $index => [$variant, $specificity]) {
            try {
                $decoded = $variant->__invoke($value, $path);
            } catch (UnexpectedResponse $e) {
                $problems[] = $e->getMessage();
                continue;
            }
            foreach (array_slice($variants, $index + 1) as [$other, $otherSpecificity]) {
                if ($otherSpecificity !== $specificity) {
                    break;
                }
                try {
                    $other->__invoke($value, $path);
                } catch (UnexpectedResponse) {
                    continue;
                }
                self::fail($path, 'matches more than one of the variants it may take');
            }

            return $decoded;
        }

        return self::fail($path, 'matches none of the variants it may take (' . implode('; ', $problems) . ')');
    }

    /**
     * The value decoded by the first variant it matches (`anyOf`), most
     * specific first, as for `oneOf()`.
     *
     * @template T
     *
     * @param list<array{Closure(mixed, string): T, int}> $variants
     *
     * @return T
     */
    public static function anyOf(mixed $value, string $path, array $variants): mixed
    {
        $problems = [];
        foreach ($variants as [$variant]) {
            try {
                return $variant->__invoke($value, $path);
            } catch (UnexpectedResponse $e) {
                $problems[] = $e->getMessage();
            }
        }

        return self::fail($path, 'matches none of the variants it may take (' . implode('; ', $problems) . ')');
    }

    /**
     * The path of `$key` inside the object at `$path`.
     */
    public static function field(string $path, string $key): string
    {
        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $key) === 1
            ? $path . '.' . $key
            : $path . '[' . json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ']';
    }

    /**
     * @throws UnexpectedResponse
     */
    public static function fail(string $path, string $problem): never
    {
        throw new UnexpectedResponse($path, $problem);
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) && $value !== [] ? 'array' : 'object',
            default => get_debug_type($value),
        };
    }
}
