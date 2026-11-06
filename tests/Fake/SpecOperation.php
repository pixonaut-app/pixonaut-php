<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Fake;

use stdClass;

/**
 * One operation of the pinned spec, as the fake routes and checks it.
 */
final class SpecOperation
{
    public const JSON = 'application/json';

    public const IDEMPOTENCY_KEY = 'idempotency-key';

    public readonly string $id;

    /** @var list<SpecParameter> */
    public readonly array $parameters;

    /**
     * Each way the operation may be authorised: the security schemes one
     * requirement names, all needed together. An empty list is anonymous.
     *
     * @var list<list<string>>
     */
    public readonly array $security;

    private readonly string $pattern;

    public function __construct(
        private readonly Spec $spec,
        public readonly string $path,
        public readonly string $method,
        private readonly stdClass $operation,
    ) {
        $this->id = is_string($operation->operationId ?? null) ? $operation->operationId : '';

        $parameters = [];
        foreach (Spec::list($operation->parameters ?? null) as $index => $parameter) {
            $parameter = Spec::object($parameter);
            $parameters[] = new SpecParameter(
                is_string($parameter->name ?? null) ? $parameter->name : '',
                is_string($parameter->in ?? null) ? $parameter->in : '',
                ($parameter->required ?? false) === true,
                [...$this->pointer(), 'parameters', (string) $index, 'schema'],
                Spec::object($parameter->schema ?? null),
            );
        }
        $this->parameters = $parameters;

        $security = [];
        // An operation without `security` takes the document's, and the
        // pinned spec has none at the top: anonymous.
        foreach (Spec::list($operation->security ?? [[]]) as $requirement) {
            $security[] = array_map('strval', array_keys(get_object_vars(Spec::object($requirement))));
        }
        $this->security = $security;

        $pattern = '';
        foreach (preg_split('/(\{[^}]+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $segment) {
            $pattern .= str_starts_with($segment, '{')
                ? '(?<' . self::group(substr($segment, 1, -1)) . '>[^/]+)'
                : preg_quote($segment, '~');
        }
        $this->pattern = '~^' . $pattern . '\z~';
    }

    /**
     * The path's values by name, raw-URL-decoded, or null when the path is
     * not this operation's.
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->pattern, $path, $found) !== 1) {
            return null;
        }
        $values = [];
        foreach ($this->parameters as $parameter) {
            if ($parameter->in === 'path') {
                $values[$parameter->name] = rawurldecode($found[self::group($parameter->name)] ?? '');
            }
        }

        return $values;
    }

    /**
     * How many characters of the template are not `{name}`s: a path that
     * names more is tried first.
     */
    public function literalLength(): int
    {
        return strlen((string) preg_replace('/\{[^}]+\}/', '', $this->path));
    }

    public function idempotent(): bool
    {
        return $this->parameter('header', self::IDEMPOTENCY_KEY) !== null;
    }

    public function anonymous(): bool
    {
        return in_array([], $this->security, true);
    }

    public function parameter(string $in, string $name): ?SpecParameter
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->in === $in && strtolower($parameter->name) === strtolower($name)) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * @return list<SpecParameter>
     */
    public function parametersIn(string $in): array
    {
        return array_values(array_filter($this->parameters, static fn(SpecParameter $parameter): bool => $parameter->in === $in));
    }

    public function hasBody(): bool
    {
        return isset($this->operation->requestBody);
    }

    public function bodyRequired(): bool
    {
        return (Spec::object($this->operation->requestBody ?? null)->required ?? false) === true;
    }

    /**
     * The first example of the JSON request body, objects as `stdClass`.
     */
    public function requestExample(): mixed
    {
        return self::firstExample($this->requestContent()->{self::JSON} ?? null);
    }

    /**
     * @return list<string>
     */
    public function validateBody(mixed $body): array
    {
        return $this->spec->validate([...$this->pointer(), 'requestBody', 'content', self::JSON, 'schema'], $body);
    }

    /**
     * Every status the spec documents an answer with.
     *
     * @return list<int>
     */
    public function statuses(): array
    {
        return array_map('intval', array_keys(get_object_vars(Spec::object($this->operation->responses ?? null))));
    }

    /**
     * @return list<int>
     */
    public function successStatuses(): array
    {
        return array_values(array_filter($this->statuses(), static fn(int $status): bool => $status >= 200 && $status < 300));
    }

    /**
     * The media types the operation answers with that status.
     *
     * @return list<string>
     */
    public function mediaTypes(int $status): array
    {
        return array_map('strval', array_keys(get_object_vars($this->responseContent($status))));
    }

    /**
     * The JSON example the spec gives for that status: the one named, or the
     * first; null when it gives none.
     */
    public function answerExample(int $status, ?string $name = null): mixed
    {
        $content = Spec::object($this->responseContent($status)->{self::JSON} ?? null);
        if ($name !== null) {
            return Spec::object(Spec::object($content->examples ?? null)->{$name} ?? null)->value ?? null;
        }

        return self::firstExample($content);
    }

    /**
     * @return list<string>
     */
    public function validateAnswer(int $status, mixed $body): array
    {
        return $this->spec->validate([...$this->pointer(), 'responses', (string) $status, 'content', self::JSON, 'schema'], $body);
    }

    /**
     * @return list<string>
     */
    public function validateParameter(SpecParameter $parameter, mixed $value): array
    {
        return $this->spec->validate($parameter->pointer, $value);
    }

    /**
     * @return list<string>
     */
    private function pointer(): array
    {
        return ['paths', $this->path, strtolower($this->method)];
    }

    private function requestContent(): stdClass
    {
        return Spec::object(Spec::object($this->operation->requestBody ?? null)->content ?? null);
    }

    private function responseContent(int $status): stdClass
    {
        return Spec::object(Spec::object(Spec::object($this->operation->responses ?? null)->{(string) $status} ?? null)->content ?? null);
    }

    private static function firstExample(mixed $content): mixed
    {
        $content = Spec::object($content);
        if (property_exists($content, 'example')) {
            return $content->example;
        }
        foreach (get_object_vars(Spec::object($content->examples ?? null)) as $example) {
            return Spec::object($example)->value ?? null;
        }

        return null;
    }

    /** A PCRE group name for a path value's name, which may hold any character. */
    private static function group(string $name): string
    {
        return 'p' . bin2hex($name);
    }
}
