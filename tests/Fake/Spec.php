<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Fake;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use stdClass;

/**
 * The pinned spec, decoded once with objects kept as `stdClass` (so `{}`
 * stays an object), its operations by id and route, and a JSON Schema
 * validator over it.
 */
final class Spec
{
    private const FILE = __DIR__ . '/../../spec/openapi.json';

    /** The id the spec is registered under, so its `$ref`s resolve within it. */
    private const DOCUMENT_ID = 'https://spec.pixonaut.test/openapi.json';

    /** Errors kept per validation; the first few say what is wrong. */
    private const MAX_ERRORS = 5;

    private static ?self $instance = null;

    /** @var array<string, SpecOperation> */
    private array $operations = [];

    /** @var list<SpecOperation> Most literal path first, so `/v1/designs/batch` wins over `/v1/designs/{id}`. */
    private array $routes = [];

    private readonly Validator $validator;

    private function __construct(private readonly stdClass $document)
    {
        $this->validator = new Validator();
        $this->validator->parser()->setOption('defaultDraft', '2020-12');
        $this->validator->setMaxErrors(self::MAX_ERRORS);
        $this->validator->resolver()?->registerRaw($document, self::DOCUMENT_ID);

        $paths = self::object($document->paths ?? null);
        foreach (get_object_vars($paths) as $path => $item) {
            $item = self::object($item);
            foreach (get_object_vars($item) as $method => $operation) {
                if (!$operation instanceof stdClass || !is_string($operation->operationId ?? null)) {
                    continue;
                }
                $spec = new SpecOperation($this, (string) $path, strtoupper((string) $method), $operation);
                $this->operations[$spec->id] = $spec;
                $this->routes[] = $spec;
            }
        }
        usort($this->routes, static fn(SpecOperation $a, SpecOperation $b): int => $b->literalLength() <=> $a->literalLength());
    }

    public static function pinned(): self
    {
        if (self::$instance === null) {
            $document = json_decode((string) file_get_contents(self::FILE), false, 512, JSON_THROW_ON_ERROR);
            self::$instance = new self(self::object($document));
        }

        return self::$instance;
    }

    /**
     * @return array<string, SpecOperation> By operation id, in the spec's order.
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function operation(string $id): SpecOperation
    {
        return $this->operations[$id] ?? throw new \OutOfBoundsException('The spec has no operation ' . $id . '.');
    }

    /**
     * The operation a request goes to and its path values, or null when no
     * operation of that method has a matching path.
     *
     * @return array{SpecOperation, array<string, string>}|null
     */
    public function route(string $method, string $path): ?array
    {
        foreach ($this->routes as $operation) {
            $values = $operation->method === $method ? $operation->match($path) : null;
            if ($values !== null) {
                return [$operation, $values];
            }
        }

        return null;
    }

    /**
     * Whether any operation, of any method, has a matching path.
     */
    public function hasPath(string $path): bool
    {
        foreach ($this->routes as $operation) {
            if ($operation->match($path) !== null) {
                return true;
            }
        }

        return false;
    }

    public function document(): stdClass
    {
        return $this->document;
    }

    /**
     * Validates a decoded JSON value against the schema at a JSON pointer
     * into the spec.
     *
     * @param list<string> $pointer The pointer's segments, unescaped.
     *
     * @return list<string> What is wrong, as `<path>: <message>`; empty when valid.
     */
    public function validate(array $pointer, mixed $value): array
    {
        $fragment = implode('/', array_map(
            static fn(string $segment): string => rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $segment)),
            $pointer,
        ));
        $result = $this->validator->validate($value, self::DOCUMENT_ID . '#/' . $fragment);
        $error = $result->error();
        if ($error === null) {
            return [];
        }
        $problems = [];
        foreach ((new ErrorFormatter())->format($error, true) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $problems[] = ($path === '' ? '/' : $path) . ': ' . (is_string($message) ? $message : '');
            }
        }

        return $problems;
    }

    public static function object(mixed $value): stdClass
    {
        return $value instanceof stdClass ? $value : new stdClass();
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
