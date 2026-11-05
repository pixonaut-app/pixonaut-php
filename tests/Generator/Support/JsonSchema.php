<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator\Support;

use stdClass;

/**
 * Validates a decoded JSON value (objects as `stdClass`) against the subset
 * of JSON Schema 2020-12 the spec uses.
 */
final class JsonSchema
{
    private const COMPONENT_REF = '#/components/schemas/';

    /**
     * @param array<string, mixed> $spec
     */
    public function __construct(
        private readonly array $spec,
    ) {}

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> What is wrong, by JSON path; empty when valid.
     */
    public function validate(array $schema, mixed $value, string $path = '$'): array
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $name = substr($schema['$ref'], strlen(self::COMPONENT_REF));
            /** @var array{components: array{schemas: array<string, array<string, mixed>>}} $spec */
            $spec = $this->spec;
            $target = $spec['components']['schemas'][$name] ?? [];

            return $this->validate($target, $value, $path);
        }

        $problems = [];
        if (isset($schema['type'])) {
            /** @var list<string> $types */
            $types = (array) $schema['type'];
            if (!in_array(self::typeOf($value), $types, true) && !(self::typeOf($value) === 'integer' && in_array('number', $types, true))) {
                return [sprintf('%s: expected %s, got %s', $path, implode('|', $types), self::typeOf($value))];
            }
        }
        if (array_key_exists('const', $schema) && $value != $schema['const']) {
            $problems[] = $path . ': not the constant';
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], false)) {
            $problems[] = $path . ': not one of the enum';
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword])) {
                continue;
            }
            $matches = 0;
            foreach ($schema[$keyword] as $variant) {
                /** @var array<string, mixed> $variant */
                if ($this->validate($variant, $value, $path) === []) {
                    ++$matches;
                }
            }
            if ($matches === 0 || ($keyword === 'oneOf' && $matches > 1)) {
                $problems[] = sprintf('%s: %d variants of the %s match', $path, $matches, $keyword);
            }
        }

        if ($value instanceof stdClass) {
            $problems = [...$problems, ...$this->object($schema, $value, $path)];
        } elseif (is_array($value)) {
            $problems = [...$problems, ...$this->array($schema, $value, $path)];
        } elseif (is_string($value)) {
            $length = mb_strlen($value);
            if ((isset($schema['minLength']) && $length < $schema['minLength']) || (isset($schema['maxLength']) && $length > $schema['maxLength'])) {
                $problems[] = $path . ': length out of range';
            }
            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                $matched = @preg_match('/' . str_replace('/', '\\/', $schema['pattern']) . '/u', $value);
                if ($matched === 0) {
                    $problems[] = $path . ': does not match ' . $schema['pattern'];
                }
            }
        } elseif (is_int($value) || is_float($value)) {
            if ((isset($schema['minimum']) && $value < $schema['minimum']) || (isset($schema['maximum']) && $value > $schema['maximum'])
                || (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum'])
                || (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum'])) {
                $problems[] = $path . ': out of range';
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function object(array $schema, stdClass $value, string $path): array
    {
        $problems = [];
        $fields = get_object_vars($value);
        /** @var list<string> $requiredFields */
        $requiredFields = (array) ($schema['required'] ?? []);
        foreach ($requiredFields as $required) {
            if (!array_key_exists($required, $fields)) {
                $problems[] = sprintf('%s: missing %s', $path, $required);
            }
        }
        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'] ?? [];
        $additional = $schema['additionalProperties'] ?? true;
        foreach ($fields as $key => $field) {
            $fieldPath = $path . '.' . $key;
            if (isset($properties[$key])) {
                $problems = [...$problems, ...$this->validate($properties[$key], $field, $fieldPath)];
            } elseif ($additional === false) {
                $problems[] = $fieldPath . ': not allowed';
            } elseif (is_array($additional)) {
                /** @var array<string, mixed> $additional */
                $problems = [...$problems, ...$this->validate($additional, $field, $fieldPath)];
            }
        }

        return $problems;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param array<array-key, mixed> $value
     *
     * @return list<string>
     */
    private function array(array $schema, array $value, string $path): array
    {
        $problems = [];
        $count = count($value);
        if ((isset($schema['minItems']) && $count < $schema['minItems']) || (isset($schema['maxItems']) && $count > $schema['maxItems'])) {
            $problems[] = $path . ': item count out of range';
        }
        /** @var list<array<string, mixed>> $prefix */
        $prefix = $schema['prefixItems'] ?? [];
        $items = $schema['items'] ?? null;
        foreach (array_values($value) as $index => $item) {
            $itemPath = $path . '[' . $index . ']';
            if (isset($prefix[$index])) {
                $problems = [...$problems, ...$this->validate($prefix[$index], $item, $itemPath)];
            } elseif ($items === false) {
                $problems[] = $itemPath . ': not allowed';
            } elseif (is_array($items)) {
                /** @var array<string, mixed> $items */
                $problems = [...$problems, ...$this->validate($items, $item, $itemPath)];
            }
        }

        return $problems;
    }

    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => floor($value) === $value ? 'integer' : 'number',
            is_string($value) => 'string',
            $value instanceof stdClass => 'object',
            default => 'array',
        };
    }
}
