<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract\Support;

use Pixonaut\Sdk\Generator\Codegen\Naming;
use Pixonaut\Sdk\Model\Model;
use Pixonaut\Sdk\Pixonaut;
use Pixonaut\Sdk\Tests\Fake\Spec;
use Pixonaut\Sdk\Tests\Fake\SpecOperation;
use Pixonaut\Sdk\Tests\Fake\SpecParameter;
use Pixonaut\Sdk\Tests\Generator\Support\Calls;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use stdClass;
use UnexpectedValueException;

/**
 * Arguments for an operation, built from the spec: its request example for
 * the body, and for each parameter the example, default or enum value its
 * schema gives, or else a value of its type that the schema accepts.
 */
final class Arguments
{
    /** Text tried for a string parameter whose schema gives no value, until one fits its pattern. */
    private const STRING_GUESSES = ['example', '1', '1.0.0', '1:1', 'abc-1', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];

    /**
     * The resource method each operation is sent by.
     *
     * @return array<string, array{string, string}> Operation id → resource accessor, method.
     */
    public static function resourceMethods(): array
    {
        $methods = [];
        foreach ((new \ReflectionClass(Pixonaut::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $accessor) {
            $type = $accessor->getReturnType();
            if (!$type instanceof ReflectionNamedType || !str_starts_with($type->getName(), 'Pixonaut\\Sdk\\Generated\\Resource\\')) {
                continue;
            }
            $operations = constant($type->getName() . '::OPERATIONS');
            foreach (is_array($operations) ? $operations : [] as $method => $id) {
                if (is_string($id)) {
                    $methods[$id] = [$accessor->getName(), (string) $method];
                }
            }
        }

        return $methods;
    }

    /**
     * Named arguments for the resource method.
     *
     * @return array<string, mixed>
     */
    public static function forMethod(SpecOperation $operation, ReflectionMethod $method): array
    {
        $byVariable = [];
        foreach ($operation->parameters as $parameter) {
            if (strtolower($parameter->name) !== SpecOperation::IDEMPOTENCY_KEY) {
                $byVariable[Naming::parameter($parameter->name, $parameter->in)] = $parameter;
            }
        }
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $arguments[$name] = match (true) {
                $name === 'body' => self::bodyModel($parameter, $operation->requestExample()),
                $name === 'idempotencyKey' => self::key($operation),
                isset($byVariable[$name]) => self::value($operation, $byVariable[$name], self::acceptsArray($parameter->getType())),
                default => throw new \LogicException(sprintf('%s(): $%s is no parameter of %s.', $method->getName(), $name, $operation->id)),
            };
        }

        return $arguments;
    }

    /**
     * Arguments for `Pixonaut::call()`.
     *
     * @return array{path: array<string, string|int>, query: array<string, scalar|list<scalar>|null>, body: array<array-key, mixed>|null, idempotencyKey: string|null, headers: array<string, string|null>}
     */
    public static function forCall(SpecOperation $operation): array
    {
        $path = [];
        $query = [];
        $headers = [];
        foreach ($operation->parameters as $parameter) {
            if (strtolower($parameter->name) === SpecOperation::IDEMPOTENCY_KEY) {
                continue;
            }
            $value = self::value($operation, $parameter, false);
            if (is_array($value)) {
                throw new \LogicException('A single value was asked for.');
            }
            $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            match ($parameter->in) {
                'path' => $path[$parameter->name] = $text,
                'query' => $query[$parameter->name] = $value,
                default => $headers[$parameter->name] = $text,
            };
        }
        $example = $operation->hasBody() ? $operation->requestExample() : null;

        return [
            'path' => $path,
            'query' => $query,
            // Nested objects stay `stdClass`, so an empty one is sent as `{}`.
            'body' => $example instanceof stdClass ? (array) $example : (is_array($example) ? $example : null),
            'idempotencyKey' => $operation->idempotent() ? self::key($operation) : null,
            'headers' => $headers,
        ];
    }

    public static function key(SpecOperation $operation): string
    {
        return 'contract-' . $operation->id;
    }

    /**
     * A value of the parameter that its schema accepts.
     *
     * @return string|int|float|bool|list<string|int|float|bool>
     */
    public static function value(SpecOperation $operation, SpecParameter $parameter, bool $asList): string|int|float|bool|array
    {
        foreach (self::guesses($parameter->schema, $parameter->name) as $guess) {
            $candidate = $asList && !is_array($guess) ? [$guess] : $guess;
            if (!$asList && is_array($candidate)) {
                continue;
            }
            if ($operation->validateParameter($parameter, $candidate) === []) {
                return $candidate;
            }
        }

        throw new \LogicException(sprintf('%s: no value found for the %s parameter %s.', $operation->id, $parameter->in, $parameter->name));
    }

    /**
     * @return list<string|int|float|bool|list<string|int|float|bool>>
     */
    private static function guesses(stdClass $schema, string $name): array
    {
        $guesses = [];
        foreach ([...Spec::list($schema->examples ?? null), $schema->example ?? null, $schema->default ?? null, Spec::list($schema->enum ?? null)[0] ?? null, $schema->const ?? null] as $given) {
            if (is_scalar($given)) {
                $guesses[] = $given;
            }
        }
        $types = is_array($schema->type ?? null) ? $schema->type : [$schema->type ?? null];
        foreach ([...Spec::list($schema->anyOf ?? null), ...Spec::list($schema->oneOf ?? null)] as $variant) {
            $variant = Spec::object($variant);
            $types[] = $variant->type ?? null;
            $guesses = [...$guesses, ...self::guesses($variant, $name)];
        }
        if (in_array('integer', $types, true) || in_array('number', $types, true)) {
            $minimum = $schema->minimum ?? (isset($schema->exclusiveMinimum) && is_numeric($schema->exclusiveMinimum) ? $schema->exclusiveMinimum + 1 : 1);
            $guesses[] = is_numeric($minimum) ? (int) ceil((float) $minimum) : 1;
        }
        if (in_array('boolean', $types, true)) {
            $guesses[] = true;
        }
        if (in_array('array', $types, true)) {
            $items = Spec::object($schema->items ?? null);
            $guesses[] = array_values(array_filter(self::guesses($items, $name), is_scalar(...)));
        }
        $guesses[] = 'example-' . strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name));
        foreach (self::STRING_GUESSES as $text) {
            $guesses[] = $text;
        }

        return $guesses;
    }

    /**
     * The model a body parameter takes, decoded from the example: for a
     * union, the variant that gives the example back.
     */
    private static function bodyModel(ReflectionParameter $parameter, mixed $example): Model
    {
        $data = json_decode((string) json_encode($example, JSON_THROW_ON_ERROR), true);
        if (!is_array($data)) {
            throw new \LogicException('The request example is not an object.');
        }
        /** @var array<string, mixed> $data */
        $type = $parameter->getType();
        $classes = $type instanceof ReflectionUnionType
            ? array_map(static fn(ReflectionType $part): string => $part instanceof ReflectionNamedType ? $part->getName() : '', $type->getTypes())
            : [$type instanceof ReflectionNamedType ? $type->getName() : ''];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }
            try {
                $model = Calls::fromArray($class, $data);
            } catch (UnexpectedValueException) {
                continue;
            }
            if (self::canonical($data) === self::canonical($model->toArray())) {
                return $model;
            }
        }

        throw new \LogicException('No body model of ' . $parameter->getDeclaringFunction()->getName() . '() gives back the example.');
    }

    private static function acceptsArray(?ReflectionType $type): bool
    {
        $parts = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($parts as $part) {
            if ($part instanceof ReflectionNamedType && $part->getName() === 'array') {
                return true;
            }
        }

        return false;
    }

    /**
     * A decoded JSON value as text that ignores key order and `1` against `1.0`.
     */
    private static function canonical(mixed $value): string
    {
        $normalise = static function (mixed $value) use (&$normalise): mixed {
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    ksort($value);
                }

                return array_map($normalise, $value);
            }

            return is_float($value) && floor($value) === $value ? (int) $value : $value;
        };

        return (string) json_encode($normalise(json_decode((string) json_encode($value), true)));
    }
}
