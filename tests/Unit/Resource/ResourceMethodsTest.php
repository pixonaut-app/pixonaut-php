<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Resource;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generated\Resource;
use Pixonaut\Sdk\Generator\Codegen\Naming;
use Pixonaut\Sdk\Http\Binary;
use Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\Sdk\Model\Model;
use Pixonaut\Sdk\Pixonaut;
use Pixonaut\Sdk\Tests\Generator\Support\Calls;
use Pixonaut\Sdk\Tests\Unit\Resource\Support\SpecClient;
use Pixonaut\Sdk\Tests\Unit\Resource\Support\SpecExamples;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Every resource method against the pinned spec: it sends the method, path,
 * query, body and headers the spec describes, and decodes the spec's
 * example answer into what it returns.
 */
final class ResourceMethodsTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../../generator/config.php';

    /** What the test passes as an idempotent operation's key. */
    private const KEY = 'key-1';

    /**
     * Each resource the client reaches, by its accessor.
     *
     * @return array<string, Closure(Pixonaut): object>
     */
    private static function resources(): array
    {
        return [
            'blanks' => static fn(Pixonaut $p): object => $p->blanks(),
            'connect' => static fn(Pixonaut $p): object => $p->connect(),
            'designerLinks' => static fn(Pixonaut $p): object => $p->designerLinks(),
            'designs' => static fn(Pixonaut $p): object => $p->designs(),
            'events' => static fn(Pixonaut $p): object => $p->events(),
            'orders' => static fn(Pixonaut $p): object => $p->orders(),
            'printshops' => static fn(Pixonaut $p): object => $p->printshops(),
            'prints' => static fn(Pixonaut $p): object => $p->prints(),
            'proofs' => static fn(Pixonaut $p): object => $p->proofs(),
            'shareLinks' => static fn(Pixonaut $p): object => $p->shareLinks(),
            'templates' => static fn(Pixonaut $p): object => $p->templates(),
            'webhooks' => static fn(Pixonaut $p): object => $p->webhooks(),
        ];
    }

    /**
     * @return array<string, array<string, string>> Accessor → method → operation id.
     */
    private static function methods(): array
    {
        return [
            'blanks' => Resource\Blanks::OPERATIONS,
            'connect' => Resource\Connect::OPERATIONS,
            'designerLinks' => Resource\DesignerLinks::OPERATIONS,
            'designs' => Resource\Designs::OPERATIONS,
            'events' => Resource\Events::OPERATIONS,
            'orders' => Resource\Orders::OPERATIONS,
            'printshops' => Resource\Printshops::OPERATIONS,
            'prints' => Resource\Prints::OPERATIONS,
            'proofs' => Resource\Proofs::OPERATIONS,
            'shareLinks' => Resource\ShareLinks::OPERATIONS,
            'templates' => Resource\Templates::OPERATIONS,
            'webhooks' => Resource\Webhooks::OPERATIONS,
        ];
    }

    public function testEveryOperationOfAMappedTagHasAMethod(): void
    {
        /** @var array{resources: array<string, string|null>, operationResources: array<string, string|null>, generateSkipped: array<string, string>} $config */
        $config = require self::CONFIG;
        $expected = [];
        foreach (SpecExamples::operations() as $id => $operation) {
            if (($operation['x-sdk-skip'] ?? false) === true && !isset($config['generateSkipped'][$id])) {
                continue;
            }
            $tags = $operation['tags'] ?? [];
            $tag = is_array($tags) && is_string($tags[0] ?? null) ? $tags[0] : '';
            self::assertArrayHasKey($tag, $config['resources'], $id . ' is in a tag the config does not map.');
            $resource = array_key_exists($id, $config['operationResources']) ? $config['operationResources'][$id] : $config['resources'][$tag];
            if ($resource !== null) {
                $expected[$id] = $resource;
            }
        }

        $covered = [];
        foreach (self::methods() as $resource => $methods) {
            foreach ($methods as $method => $id) {
                self::assertTrue(method_exists('Pixonaut\\Sdk\\Generated\\Resource\\' . ucfirst($resource), $method), $resource . '()->' . $method . '()');
                $covered[$id] = $resource;
            }
        }
        ksort($expected);
        ksort($covered);

        self::assertSame($expected, $covered);
        self::assertSame(array_keys(self::resources()), array_keys(self::methods()));
    }

    /**
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function calls(): iterable
    {
        $spec = SpecExamples::operations();
        foreach (self::methods() as $resource => $methods) {
            foreach ($methods as $method => $id) {
                foreach (array_keys(SpecExamples::map($spec[$id]['responses'] ?? [])) as $status) {
                    if ((int) $status >= 200 && (int) $status < 300) {
                        yield $resource . '()->' . $method . '() ' . $status => [$resource, $method, $id, (int) $status];
                    }
                }
            }
        }
    }

    #[DataProvider('calls')]
    public function testSendsWhatTheSpecDescribesAndDecodesItsExample(string $resource, string $method, string $id, int $status): void
    {
        $operation = SpecExamples::operations()[$id];
        $client = new SpecClient();
        $target = self::resources()[$resource]($client->pixonaut);
        $reflection = new ReflectionMethod($target, $method);
        [$arguments, $expected] = self::arguments($operation, $reflection);

        $answer = self::answer($client, $operation, $status);
        $returned = $target->{$method}(...$arguments);

        $sent = $client->lastSent();
        self::assertRequest($expected, $sent);
        self::assertAnswer($reflection->getReturnType(), $answer, $returned);

        if (($expected['headers']['idempotency-key'] ?? null) !== null) {
            $prepared = $target->{'prepare' . ucfirst($method)}(...$arguments);
            self::assertInstanceOf(PreparedRequest::class, $prepared);
            $restored = PreparedRequest::fromArray((array) json_decode((string) json_encode($prepared->toArray()), true));
            self::answer($client, $operation, $status);
            $again = $target->{'send' . ucfirst($method)}($restored);

            self::assertRequest($expected, $client->lastSent());
            self::assertSame((string) $sent->getBody(), (string) $client->lastSent()->getBody());
            self::assertAnswer($reflection->getReturnType(), $answer, $again);
        }
    }

    /**
     * The arguments the test passes, and the request they make.
     *
     * @param array<array-key, mixed> $operation
     *
     * @return array{array<string, mixed>, array{method: string, path: string, query: list<string>, headers: array<string, string>, body: mixed}}
     */
    private static function arguments(array $operation, ReflectionMethod $method): array
    {
        /** @var array<string, array{name: string, in: string, schema: array<array-key, mixed>}> $byVariable */
        $byVariable = [];
        foreach (SpecExamples::list($operation['parameters'] ?? []) as $parameter) {
            $parameter = SpecExamples::map($parameter);
            $name = SpecExamples::text($parameter['name'] ?? null);
            $in = SpecExamples::text($parameter['in'] ?? null);
            if (strtolower($name) !== 'idempotency-key') {
                $byVariable[Naming::parameter($name, $in)] = ['name' => $name, 'in' => $in, 'schema' => SpecExamples::map($parameter['schema'] ?? [])];
            }
        }
        $path = SpecExamples::text($operation['path'] ?? null);
        $query = [];
        $headers = [];
        $body = null;
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            if ($name === 'body') {
                $body = SpecExamples::request(SpecExamples::text($operation['operationId'] ?? null));
                $arguments[$name] = self::bodyModel($parameter, $body);
                continue;
            }
            if ($name === 'idempotencyKey') {
                $arguments[$name] = self::KEY;
                $headers['idempotency-key'] = self::KEY;
                continue;
            }
            self::assertArrayHasKey($name, $byVariable, $method->getName() . '(): $' . $name . ' is no parameter of the operation.');
            $spec = $byVariable[$name];
            $value = self::value($spec['name'], $spec['schema']);
            $arguments[$name] = $value;
            $items = array_map(
                static fn(string|int|float|bool $item): string => is_bool($item) ? ($item ? 'true' : 'false') : (string) $item,
                is_array($value) ? $value : [$value],
            );
            match ($spec['in']) {
                'path' => $path = str_replace('{' . $spec['name'] . '}', rawurlencode($items[0]), $path),
                'query' => $query = [...$query, ...array_map(static fn(string $item): string => $spec['name'] . '=' . rawurlencode($item), $items)],
                default => $headers[strtolower($spec['name'])] = $items[0],
            };
        }
        self::assertSame(count($byVariable), count($arguments) - count(array_intersect(array_keys($arguments), ['body', 'idempotencyKey'])), 'Every parameter of the operation is one of the method\'s.');

        return [$arguments, ['method' => strtoupper(SpecExamples::text($operation['method'] ?? null)), 'path' => $path, 'query' => $query, 'headers' => $headers, 'body' => $body]];
    }

    /**
     * A value of the parameter's type, unlike any other parameter's.
     *
     * @param array<array-key, mixed> $schema
     *
     * @return string|int|float|bool|list<string>
     */
    private static function value(string $name, array $schema): string|int|float|bool|array
    {
        foreach (['anyOf', 'oneOf'] as $keyword) {
            foreach (SpecExamples::list($schema[$keyword] ?? []) as $variant) {
                if ((SpecExamples::map($variant)['type'] ?? null) === 'array') {
                    return ['a-' . $name, 'b ' . $name];
                }
            }
        }
        $declared = $schema['type'] ?? 'string';
        $types = array_map(SpecExamples::text(...), is_array($declared) ? array_values($declared) : [$declared]);
        $type = array_values(array_diff($types, ['null']))[0] ?? 'string';
        $enum = SpecExamples::list($schema['enum'] ?? []);

        return match ($type) {
            'integer' => 7,
            'number' => 1.5,
            'boolean' => true,
            'array' => ['a-' . $name, 'b ' . $name],
            default => $enum !== [] ? SpecExamples::text($enum[0]) : 'v/' . $name,
        };
    }

    /**
     * The model a body parameter takes, decoded from the example: for a
     * union, the variant that gives the example back.
     */
    private static function bodyModel(ReflectionParameter $parameter, mixed $example): Model
    {
        self::assertIsArray($example);
        /** @var array<string, mixed> $example */
        $type = $parameter->getType();
        $classes = $type instanceof ReflectionUnionType
            ? array_map(static fn(ReflectionType $part): string => $part instanceof ReflectionNamedType ? $part->getName() : '', $type->getTypes())
            : [$type instanceof ReflectionNamedType ? $type->getName() : ''];
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }
            try {
                $model = Calls::fromArray($class, $example);
            } catch (\UnexpectedValueException) {
                continue;
            }
            if (self::same($example, $model->toArray())) {
                return $model;
            }
        }
        self::fail('No body model of ' . $parameter->getDeclaringFunction()->getName() . '() gives back the example.');
    }

    /**
     * Scripts the answer the spec documents for the status.
     *
     * @param array<array-key, mixed> $operation
     */
    private static function answer(SpecClient $client, array $operation, int $status): mixed
    {
        $id = SpecExamples::text($operation['operationId'] ?? null);
        $content = SpecExamples::content($id, $status);
        if ($content === []) {
            $client->answer($status, '');

            return null;
        }
        if (isset($content[SpecExamples::JSON])) {
            $example = SpecExamples::answer($id, $status);
            $client->answerJson($status, $example);

            return $example;
        }
        $type = (string) array_key_first($content);
        $client->answer($status, "\x00bytes of " . $type, ['Content-Type' => $type, 'X-Request-Id' => 'req_1']);

        return new Binary($type, "\x00bytes of " . $type, 'req_1');
    }

    /**
     * @param array{method: string, path: string, query: list<string>, headers: array<string, string>, body: mixed} $expected
     */
    private static function assertRequest(array $expected, RequestInterface $sent): void
    {
        self::assertSame($expected['method'], $sent->getMethod());
        self::assertSame(SpecClient::BASE_URL . $expected['path'], (string) $sent->getUri()->withQuery(''));
        self::assertSame(implode('&', $expected['query']), $sent->getUri()->getQuery());
        foreach ($expected['headers'] as $name => $value) {
            self::assertSame($value, $sent->getHeaderLine($name), $name);
        }
        if (!isset($expected['headers']['idempotency-key'])) {
            self::assertFalse($sent->hasHeader('Idempotency-Key'));
        }
        if ($expected['body'] === null) {
            self::assertSame('', (string) $sent->getBody());
        } else {
            self::assertTrue(self::same($expected['body'], json_decode((string) $sent->getBody(), true)), 'The body is the example.');
        }
    }

    private static function assertAnswer(?ReflectionType $type, mixed $answer, mixed $returned): void
    {
        $names = $type instanceof ReflectionUnionType
            ? array_map(static fn(ReflectionType $part): string => $part instanceof ReflectionNamedType ? $part->getName() : '', $type->getTypes())
            : [$type instanceof ReflectionNamedType ? $type->getName() : ''];
        if ($names === ['void'] || $answer === null) {
            self::assertNull($returned);

            return;
        }
        self::assertTrue(array_filter($names, static fn(string $name): bool => $name === 'array' ? is_array($returned) : $returned instanceof $name) !== [], 'Returns one of ' . implode('|', $names) . ', got ' . get_debug_type($returned));
        if (is_array($returned)) {
            self::assertTrue(self::same($answer, array_map(static fn(mixed $item): mixed => $item instanceof Model ? $item->raw() : $item, $returned)), 'The list holds the example.');
        }
        if ($returned instanceof Binary) {
            self::assertEquals($answer, $returned);
        }
        if ($returned instanceof Model) {
            self::assertTrue(self::same($answer, $returned->raw()), 'The model holds the example.');
        }
    }

    /**
     * Whether two decoded JSON values are equal, `1` and `1.0` alike.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        // Through JSON first: a model sends an empty object as `stdClass`.
        $decode = static fn(mixed $value): mixed => json_decode((string) json_encode($value), true);

        return json_encode(self::normalise($decode($a))) === json_encode(self::normalise($decode($b)));
    }

    private static function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map(self::normalise(...), $value);
        }

        return is_float($value) && floor($value) === $value ? (int) $value : $value;
    }
}
