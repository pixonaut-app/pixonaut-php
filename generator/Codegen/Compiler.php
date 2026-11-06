<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

use Pixonaut\Sdk\Model\Decode;

/**
 * Reads an OpenAPI 3.1 document, as plain JSON, into the models, unions,
 * enums and operations the generator prints.
 *
 * Request and response bodies are inline, so their schemas are named after
 * the operation and their place in it (`OrdersCreateRequestPrint`); a named
 * component keeps its own name. Two places that would get the same name stop
 * the generator.
 */
final class Compiler
{
    private const COMPONENT_REF = '#/components/schemas/';

    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private const JSON = 'application/json';

    private const EVENT_STREAM = 'text/event-stream';

    /** The class decoding a webhook delivery's body by its `type`. */
    public const WEBHOOK_EVENT = 'WebhookEvent';

    /** The envelope field naming an event's type. */
    private const EVENT_TYPE_FIELD = 'type';

    private const IDEMPOTENCY_HEADER = 'idempotency-key';

    /** The lowest status of an answer that is not a success. */
    private const FIRST_UNSUCCESSFUL_STATUS = 300;

    /** Statuses from here on are refusals, whose body names an error code. */
    private const FIRST_ERROR_STATUS = 400;

    /** What a variant's tag (a required field allowing one value) outweighs: any count of required fields. */
    private const TAG_WEIGHT = 1000;

    /** @var array<string, ModelDef> */
    private array $models = [];

    /** @var array<string, UnionDef> */
    private array $unions = [];

    /** @var array<string, EnumDef> */
    private array $enums = [];

    /** @var array<string, string> Class name → where it was defined. */
    private array $classes = [];

    /** @var array<string, Type> */
    private array $components = [];

    /** @var array<string, true> */
    private array $componentsInProgress = [];

    /** @var array<string, true> Every component a webhook's body refers to. */
    private array $eventComponents = [];

    /**
     * @param array<string, mixed> $spec
     */
    public function __construct(
        private readonly array $spec,
        private readonly Config $config,
    ) {}

    public function compile(): Api
    {
        foreach ($this->webhooks() as $webhook) {
            $ref = self::map($webhook['schema'])['$ref'] ?? null;
            if (is_string($ref)) {
                $this->eventComponents[$ref] = true;
            }
        }
        foreach (array_keys(self::map(self::map($this->spec['components'] ?? [])['schemas'] ?? [])) as $name) {
            $this->component(self::COMPONENT_REF . $name);
        }

        $operations = [];
        $requests = [];
        foreach ($this->operations() as [$path, $method, $operation]) {
            $id = self::text($operation['operationId'] ?? null);
            if (($operation['x-sdk-skip'] ?? false) === true && !isset($this->config->generateSkipped[$id])) {
                continue;
            }
            $definition = $this->operation($id, $path, $method, $operation);
            if ($definition->request !== null) {
                $requests[] = $definition->request;
            }
            $operations[] = $definition;
        }
        foreach (array_keys($this->config->sensitive + $this->config->generateSkipped) as $id) {
            if (!in_array($id, array_map(static fn(OperationDef $operation): string => $operation->id, $operations), true)) {
                throw new GeneratorException(sprintf('generator/config.php names %s, which the spec does not have.', $id));
            }
        }
        foreach ($requests as $request) {
            $this->markRequest($request);
        }
        [$codes, $descriptions, $statuses] = $this->errors();
        $eventTypes = $this->eventTypes();

        return new Api(
            self::text(self::map($this->spec['info'] ?? [])['version'] ?? null),
            $this->models,
            $this->unions,
            $this->enums,
            $operations,
            $codes,
            $descriptions,
            $statuses,
            $eventTypes,
        );
    }

    /**
     * Every webhook the spec describes, by its event type, with its body's
     * schema and its description.
     *
     * @return array<string, array{schema: array<string, mixed>, description: string}>
     */
    private function webhooks(): array
    {
        $webhooks = [];
        foreach (self::map($this->spec['webhooks'] ?? []) as $type => $item) {
            $post = self::map(self::map($item)['post'] ?? []);
            $schema = self::map(self::map(self::map(self::map($post['requestBody'] ?? [])['content'] ?? [])[self::JSON] ?? [])['schema'] ?? []);
            if ($schema === []) {
                throw new GeneratorException(sprintf('Webhook %s has no JSON body.', $type));
            }
            $webhooks[(string) $type] = ['schema' => $schema, 'description' => self::text($post['description'] ?? '')];
        }

        return $webhooks;
    }

    /**
     * Compiles each webhook's body into its event class, and the decoder
     * choosing among them by `type`.
     *
     * @return array<string, string> Event type → its description.
     */
    private function eventTypes(): array
    {
        $types = [];
        $mapping = [];
        foreach ($this->webhooks() as $type => $webhook) {
            $name = Naming::className(Naming::pascal($type) . 'Event');
            $mapping[$type] = $this->schema($webhook['schema'], $name, 'webhook ' . $type . ': $');
            $types[$type] = $webhook['description'];
        }
        if ($mapping === []) {
            return [];
        }
        $this->claim(self::WEBHOOK_EVENT, 'webhooks');
        $this->unions[self::WEBHOOK_EVENT] = new UnionDef(
            self::WEBHOOK_EVENT,
            UnionDef::DISCRIMINATED,
            'A webhook delivery\'s body, as the event class its type names.',
            [],
            'webhooks',
            self::EVENT_TYPE_FIELD,
            $mapping,
            events: true,
        );
        ksort($types, SORT_STRING);

        return $types;
    }

    /**
     * Every operation in the spec's order.
     *
     * @return list<array{string, string, array<string, mixed>}>
     */
    public function operations(): array
    {
        $operations = [];
        foreach (self::map($this->spec['paths'] ?? []) as $path => $item) {
            foreach (self::map($item) as $method => $operation) {
                if (in_array($method, self::HTTP_METHODS, true)) {
                    $operations[] = [(string) $path, $method, self::map($operation)];
                }
            }
        }

        return $operations;
    }

    /**
     * The class name of an operation's request body.
     */
    public static function requestName(string $operationId): string
    {
        return Naming::className(Naming::pascal($operationId) . 'Request');
    }

    /**
     * The class name of an operation's successful JSON answer.
     *
     * @param list<int> $jsonStatuses Every status it answers JSON with on success.
     */
    public static function responseName(string $operationId, int $status, array $jsonStatuses): string
    {
        return Naming::className(Naming::pascal($operationId) . 'Response' . (count($jsonStatuses) > 1 ? $status : ''));
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function operation(string $id, string $path, string $method, array $operation): OperationDef
    {
        $origin = strtoupper($method) . ' ' . $path;
        $request = null;
        $body = self::map(self::map(self::map($operation['requestBody'] ?? [])['content'] ?? [])[self::JSON] ?? []);
        if (isset($body['schema'])) {
            $request = $this->schema(self::map($body['schema']), self::requestName($id), $origin . ' request body: $');
        }

        $accept = [];
        $binaryStatuses = [];
        $streamStatuses = [];
        $emptyStatuses = [];
        $errorCodes = [];
        foreach (self::map($operation['responses'] ?? []) as $status => $response) {
            $content = self::map(self::map($response)['content'] ?? []);
            if (self::isSuccess((string) $status)) {
                if ($content === []) {
                    $emptyStatuses[] = (int) $status;
                } elseif (array_keys($content) === [self::EVENT_STREAM]) {
                    $streamStatuses[] = (int) $status;
                } elseif (array_diff(array_keys($content), [self::JSON]) !== []) {
                    $binaryStatuses[] = (int) $status;
                }
            } elseif ((int) $status >= self::FIRST_ERROR_STATUS) {
                $errorCodes = [...$errorCodes, ...self::documentedCodes($content)];
            }
        }
        $errorCodes = array_values(array_unique($errorCodes));
        sort($errorCodes);
        $jsonStatuses = [];
        foreach (self::map($operation['responses'] ?? []) as $status => $response) {
            if (self::isSuccess((string) $status) && isset(self::map(self::map($response)['content'] ?? [])[self::JSON])) {
                $jsonStatuses[] = (int) $status;
            }
        }
        $responses = [];
        foreach (self::map($operation['responses'] ?? []) as $status => $response) {
            if (!self::isSuccess((string) $status)) {
                continue;
            }
            foreach (self::map(self::map($response)['content'] ?? []) as $type => $content) {
                $accept[(string) $type] = true;
                $schema = self::map($content)['schema'] ?? null;
                if ($type === self::JSON && is_array($schema)) {
                    $responses[(int) $status] = $this->schema(
                        self::map($schema),
                        self::responseName($id, (int) $status, $jsonStatuses),
                        $origin . ' answer ' . $status . ': $',
                    );
                }
            }
        }

        [$credentials, $anonymous] = $this->security($id, $operation);

        return new OperationDef(
            $id,
            strtoupper($method),
            $path,
            $credentials,
            $anonymous,
            $this->idempotent($operation),
            $accept === [] ? self::JSON : implode(', ', array_keys($accept)),
            isset($this->config->sensitive[$id]),
            self::text($operation['summary'] ?? ''),
            $request,
            $responses,
            self::text(self::list($operation['tags'] ?? [])[0] ?? ''),
            $this->parameters($id, $operation),
            (self::map($operation['requestBody'] ?? [])['required'] ?? false) === true,
            $binaryStatuses,
            $emptyStatuses,
            $errorCodes,
            $streamStatuses,
        );
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return list<ParameterDef>
     */
    private function parameters(string $id, array $operation): array
    {
        $parameters = [];
        foreach (self::list($operation['parameters'] ?? []) as $parameter) {
            $parameter = self::map($parameter);
            $name = self::text($parameter['name'] ?? '');
            $in = self::text($parameter['in'] ?? '');
            if ($in === ParameterDef::HEADER && strtolower($name) === self::IDEMPOTENCY_HEADER) {
                continue;
            }
            if (!in_array($in, [ParameterDef::PATH, ParameterDef::QUERY, ParameterDef::HEADER], true)) {
                throw new GeneratorException(sprintf('%s: parameter %s is in %s, which the SDK cannot send.', $id, $name, $in));
            }
            $schema = self::map($parameter['schema'] ?? []);
            $variable = Naming::parameter($name, $in);
            $parameters[] = new ParameterDef(
                $name,
                $in,
                $variable,
                $this->parameterType($id . ' parameter ' . $name, $schema),
                $in === ParameterDef::PATH || ($parameter['required'] ?? false) === true,
                self::text($parameter['description'] ?? $schema['description'] ?? ''),
            );
        }

        return $parameters;
    }

    /**
     * A parameter's type: a scalar, a list of scalars, or a union of those.
     *
     * @param array<string, mixed> $schema
     */
    private function parameterType(string $origin, array $schema): Type
    {
        foreach ([UnionDef::ONE_OF, UnionDef::ANY_OF] as $keyword) {
            if (isset($schema[$keyword])) {
                $variants = array_map(fn(mixed $variant): Type => $this->parameterType($origin, self::map($variant)), self::list($schema[$keyword]));

                return count($variants) === 1 ? $variants[0] : new Type(Type::UNION, variants: $variants);
            }
        }
        $types = $schema['type'] ?? 'string';
        $types = array_values(array_diff(is_array($types) ? array_map(self::text(...), $types) : [self::text($types)], ['null']));
        if (count($types) !== 1) {
            throw new GeneratorException(sprintf('%s: a parameter of several types is not supported.', $origin));
        }

        return match ($types[0]) {
            'string' => new Type(Type::STRING),
            'integer' => new Type(Type::INT),
            'number' => new Type(Type::FLOAT),
            'boolean' => new Type(Type::BOOL),
            'array' => new Type(Type::LIST, item: $this->parameterType($origin . '[]', self::map($schema['items'] ?? []))),
            default => throw new GeneratorException(sprintf('%s: a parameter of type %s is not supported.', $origin, $types[0])),
        };
    }

    /**
     * The `error` values an error answer's schema lists.
     *
     * @param array<string, mixed> $content
     *
     * @return list<string>
     */
    private static function documentedCodes(array $content): array
    {
        $schema = self::map(self::map($content[self::JSON] ?? [])['schema'] ?? []);
        $error = self::map(self::map($schema['properties'] ?? [])['error'] ?? []);

        return array_values(array_filter(self::list($error['enum'] ?? $error['x-extensible-enum'] ?? []), is_string(...)));
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array{list<string>, bool}
     */
    private function security(string $id, array $operation): array
    {
        $credentials = [];
        $requirements = self::list($operation['security'] ?? $this->spec['security'] ?? []);
        // No requirement at all, like an empty one among several, admits a call without a credential.
        $anonymous = $requirements === [];
        foreach ($requirements as $requirement) {
            $schemes = array_keys(self::map($requirement));
            if ($schemes === []) {
                $anonymous = true;
                continue;
            }
            if (count($schemes) > 1) {
                throw new GeneratorException(sprintf('%s requires several credentials at once, which the client cannot send.', $id));
            }
            $credentials[] = (string) $schemes[0];
        }

        return [$credentials, $anonymous];
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function idempotent(array $operation): bool
    {
        foreach (self::list($operation['parameters'] ?? []) as $parameter) {
            $parameter = self::map($parameter);
            if (strtolower(self::text($parameter['name'] ?? '')) === self::IDEMPOTENCY_HEADER && ($parameter['in'] ?? '') === 'header') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every error code the spec names, with the statuses it comes with.
     *
     * @return array{array<string, list<int>>, array<string, string>, list<int>}
     */
    private function errors(): array
    {
        $codes = [];
        $descriptions = [];
        foreach (self::map($this->spec['x-refusals'] ?? []) as $code => $refusal) {
            $refusal = self::map($refusal);
            $status = $refusal['status'] ?? null;
            $codes[(string) $code][is_int($status) ? $status : 0] = true;
            $descriptions[(string) $code] = self::text($refusal['description'] ?? '');
        }
        $statuses = [];
        foreach ($this->operations() as [, , $operation]) {
            foreach (self::map($operation['responses'] ?? []) as $status => $response) {
                if ((int) $status >= self::FIRST_UNSUCCESSFUL_STATUS) {
                    $statuses[(int) $status] = true;
                }
                if ((int) $status < self::FIRST_ERROR_STATUS) {
                    continue;
                }
                foreach (self::documentedCodes(self::map(self::map($response)['content'] ?? [])) as $code) {
                    $codes[$code][(int) $status] = true;
                }
            }
        }
        ksort($codes);
        $result = [];
        foreach ($codes as $code => $codeStatuses) {
            $list = array_keys($codeStatuses);
            sort($list);
            $result[$code] = $list;
        }
        $statusList = array_keys($statuses);
        sort($statusList);

        return [$result, $descriptions, $statusList];
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function schema(array $schema, string $name, string $origin): Type
    {
        if (isset($schema['$ref'])) {
            return $this->component(self::text($schema['$ref']));
        }
        if (isset($schema['allOf'])) {
            return $this->schema($this->merge($schema), $name, $origin);
        }
        foreach ([UnionDef::ONE_OF, UnionDef::ANY_OF] as $keyword) {
            if (isset($schema[$keyword])) {
                return $this->unionOf($schema, $keyword, $name, $origin);
            }
        }

        $types = $schema['type'] ?? self::inferType($schema);
        $types = is_array($types) ? array_values(array_map(self::text(...), $types)) : [self::text($types)];
        $types = array_values(array_filter($types, static fn(string $type): bool => $type !== ''));
        $nullable = in_array('null', $types, true);
        $types = array_values(array_diff($types, ['null']));
        if ($types === []) {
            return new Type($nullable ? Type::NULL : Type::MIXED);
        }
        if (count($types) > 1) {
            $variants = array_map(static fn(string $type): array => ['type' => $type] + $schema, $types);
            $type = $this->union(UnionDef::ANY_OF, $schema, $variants, $name, $origin);

            return $nullable ? $type->orNull() : $type;
        }

        $type = match ($types[0]) {
            'string' => new Type(Type::STRING, enum: $this->enum($schema, $name, $origin)),
            'integer' => new Type(Type::INT),
            'number' => new Type(Type::FLOAT),
            'boolean' => new Type(Type::BOOL),
            'object' => $this->object($schema, $name, $origin),
            'array' => $this->array($schema, $name, $origin),
            default => throw new GeneratorException(sprintf('%s: unknown type "%s".', $origin, $types[0])),
        };

        return $nullable ? $type->orNull() : $type;
    }

    /**
     * An `allOf` of object schemas as the one object schema they add up to:
     * every part's properties, every part's required fields. A property two
     * parts describe becomes an `allOf` of both.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function merge(array $schema): array
    {
        $merged = $schema;
        unset($merged['allOf']);
        $properties = self::map($merged['properties'] ?? []);
        $required = self::list($merged['required'] ?? []);
        foreach (self::list($schema['allOf']) as $part) {
            $part = self::map($part);
            if (isset($part['$ref'])) {
                $part = $this->componentSchema(self::text($part['$ref']));
            }
            if (isset($part['allOf'])) {
                $part = $this->merge($part);
            }
            foreach (['oneOf', 'anyOf', 'items', 'prefixItems', 'enum', 'const'] as $keyword) {
                if (isset($part[$keyword])) {
                    throw new GeneratorException(sprintf('An allOf part with %s is not supported.', $keyword));
                }
            }
            foreach (self::map($part['properties'] ?? []) as $key => $property) {
                $properties[$key] = isset($properties[$key]) && $properties[$key] !== $property
                    ? ['allOf' => [$properties[$key], $property]]
                    : $property;
            }
            $required = [...$required, ...self::list($part['required'] ?? [])];
            $merged['type'] ??= $part['type'] ?? null;
            if (self::text($merged['description'] ?? '') === '' && isset($part['description'])) {
                $merged['description'] = $part['description'];
            }
        }
        $merged['type'] ??= 'object';
        $merged['properties'] = $properties;
        $merged['required'] = array_values(array_unique($required, SORT_REGULAR));

        return $merged;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function unionOf(array $schema, string $keyword, string $name, string $origin): Type
    {
        $nullable = false;
        $variants = [];
        foreach (self::list($schema[$keyword]) as $variant) {
            $variant = self::map($variant);
            if (($variant['type'] ?? null) === 'null' || $variant === ['type' => ['null']]) {
                $nullable = true;
                continue;
            }
            $variants[] = $variant;
        }
        $outer = $schema['type'] ?? [];
        if ($outer === 'null' || (is_array($outer) && in_array('null', $outer, true))) {
            $nullable = true;
        }
        if ($variants === []) {
            return new Type(Type::NULL);
        }
        if (count($variants) === 1 && !isset($schema['discriminator'])) {
            $type = $this->schema($variants[0] + ['description' => $schema['description'] ?? ''], $name, $origin);

            return $nullable ? $type->orNull() : $type;
        }
        $type = $this->union($keyword, $schema, $variants, $name, $origin);

        return $nullable ? $type->orNull() : $type;
    }

    /**
     * @param array<string, mixed>       $schema
     * @param list<array<string, mixed>> $variants
     */
    private function union(string $keyword, array $schema, array $variants, string $name, string $origin): Type
    {
        $this->claim($name, $origin);
        $description = self::text($schema['description'] ?? '');
        $discriminator = self::map($schema['discriminator'] ?? []);
        if ($discriminator !== []) {
            $mapping = [];
            foreach (self::map($discriminator['mapping'] ?? []) as $value => $ref) {
                $mapping[(string) $value] = $this->component(self::text($ref));
            }
            if ($mapping === []) {
                throw new GeneratorException(sprintf('%s: a discriminator without a mapping is not supported.', $origin));
            }
            $property = self::text($discriminator['propertyName'] ?? '');
            // A list of events, such as `GET /v1/events` answers: a type added after this release is an `UnknownEvent`.
            $events = $property === self::EVENT_TYPE_FIELD
                && array_diff(array_map(self::text(...), array_values(self::map($discriminator['mapping']))), array_keys($this->eventComponents)) === [];
            $union = new UnionDef($name, UnionDef::DISCRIMINATED, $description, [], $origin, $property, $mapping, $events);
            $this->unions[$name] = $union;

            return $union->type();
        }

        $resolved = array_map($this->resolve(...), $variants);
        $names = $this->variantNames($resolved, $name);
        $compiled = [];
        foreach ($variants as $index => $variant) {
            $type = $this->schema($variant, $names[$index], sprintf('%s (%s #%d)', $origin, $keyword, $index + 1));
            $tags = $type->kind === Type::MODEL && !$type->nullable ? self::tags($resolved[$index]) : [];
            $specificity = $type->kind === Type::MODEL && !$type->nullable
                ? count($tags) * self::TAG_WEIGHT + count(self::list($resolved[$index]['required'] ?? []))
                : 0;
            $compiled[] = [$type, $specificity, $tags, $index];
        }
        // Most specific first; the spec's order among equals.
        usort($compiled, static fn(array $a, array $b): int => [$b[1], $a[3]] <=> [$a[1], $b[3]]);
        $union = new UnionDef(
            $name,
            $keyword === UnionDef::ONE_OF ? UnionDef::ONE_OF : UnionDef::ANY_OF,
            $description,
            array_map(static fn(array $variant): array => [$variant[0], $variant[1], $variant[2]], $compiled),
            $origin,
        );
        $this->unions[$name] = $union;

        return $union->type();
    }

    /**
     * A schema with its `$ref` followed and its `allOf` merged, as the
     * object it describes.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function resolve(array $schema): array
    {
        if (isset($schema['$ref'])) {
            $schema = $this->componentSchema(self::text($schema['$ref']));
        }

        return isset($schema['allOf']) ? $this->merge($schema) : $schema;
    }

    /**
     * Variants named by the value of a field that tells them apart
     * (`…StateFailing`), or by their position (`…Option1`).
     *
     * @param list<array<string, mixed>> $variants
     *
     * @return list<string>
     */
    private function variantNames(array $variants, string $name): array
    {
        $byPosition = array_map(static fn(int $index): string => $name . 'Option' . ($index + 1), array_keys($variants));
        $tags = array_map(self::tags(...), $variants);
        $candidates = array_keys(array_merge(...$tags === [] ? [[]] : $tags));
        sort($candidates);
        foreach ($candidates as $field) {
            $values = [];
            foreach ($tags as $variantTags) {
                if (array_key_exists($field, $variantTags)) {
                    $values[] = Naming::pascal(var_export($variantTags[$field], true));
                }
            }
            if ($values === [] || count(array_unique($values)) !== count($values)) {
                continue;
            }
            $names = [];
            foreach ($tags as $index => $variantTags) {
                $names[] = array_key_exists($field, $variantTags)
                    ? $name . Naming::pascal(is_string($variantTags[$field]) ? $variantTags[$field] : var_export($variantTags[$field], true))
                    : $byPosition[$index];
            }
            if (count(array_unique($names)) === count($names)) {
                return $names;
            }
        }

        return $byPosition;
    }

    /**
     * The required fields of an object schema that allow one value only.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, string|int|float|bool>
     */
    private static function tags(array $schema): array
    {
        $tags = [];
        $required = self::list($schema['required'] ?? []);
        foreach (self::map($schema['properties'] ?? []) as $key => $property) {
            $value = self::constantOf(self::map($property));
            if ($value !== null && in_array($key, $required, true)) {
                $tags[(string) $key] = $value;
            }
        }

        return $tags;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function constantOf(array $schema): string|int|float|bool|null
    {
        $value = $schema['const'] ?? null;
        if ($value === null && count(self::list($schema['enum'] ?? [])) === 1) {
            $value = self::list($schema['enum'])[0];
        }

        return is_scalar($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function object(array $schema, string $name, string $origin): Type
    {
        $properties = self::map($schema['properties'] ?? []);
        if ($properties === []) {
            $additional = $schema['additionalProperties'] ?? true;
            if (is_array($additional) && $additional !== []) {
                return new Type(Type::MAP, item: $this->schema(self::map($additional), $name . 'Value', $origin . '[*]'));
            }

            return new Type(Type::OBJECT);
        }

        $this->claim($name, $origin);
        $required = self::list($schema['required'] ?? []);
        $definitions = [];
        $names = [];
        foreach ($properties as $key => $property) {
            $key = (string) $key;
            $property = self::map($property);
            $phpName = Naming::property($key);
            if (isset($names[$phpName])) {
                throw new GeneratorException(sprintf('%s: fields "%s" and "%s" are both property $%s.', $origin, $names[$phpName], $key, $phpName));
            }
            $names[$phpName] = $key;
            $definitions[] = new PropertyDef(
                $key,
                $phpName,
                $this->schema($property, $name . Naming::pascal($key), Decode::field($origin, $key)),
                in_array($key, $required, true),
                self::text($property['description'] ?? ''),
            );
        }
        $this->models[$name] = new ModelDef($name, self::text($schema['description'] ?? ''), $definitions, $origin);

        return new Type(Type::MODEL, class: $name);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function array(array $schema, string $name, string $origin): Type
    {
        $items = $schema['items'] ?? null;
        if (isset($schema['prefixItems'])) {
            $prefix = [];
            foreach (self::list($schema['prefixItems']) as $index => $item) {
                $prefix[] = $this->schema(self::map($item), $name . 'Item' . ($index + 1), $origin . '[' . $index . ']');
            }
            $rest = is_array($items) ? $this->schema(self::map($items), $name . 'Item', $origin . '[]') : null;

            return new Type(Type::TUPLE, item: $rest, items: $prefix);
        }

        return new Type(Type::LIST, item: is_array($items) && $items !== []
            ? $this->schema(self::map($items), $name . 'Item', $origin . '[]')
            : new Type(Type::MIXED));
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function enum(array $schema, string $name, string $origin): string
    {
        $extensible = isset($schema['x-extensible-enum']);
        $values = array_values(array_filter(
            self::list($schema['x-extensible-enum'] ?? $schema['enum'] ?? []),
            is_string(...),
        ));
        if (count($values) < 2) {
            return '';
        }
        foreach ($this->enums as $existing) {
            if (strcasecmp($existing->name, $name) === 0) {
                throw new GeneratorException(sprintf('%s and %s are both enum %s.', $existing->origin, $origin, $name));
            }
        }
        $this->enums[$name] = new EnumDef($name, self::text($schema['description'] ?? ''), $values, $extensible, $origin);

        return $name;
    }

    private function component(string $ref): Type
    {
        if (!str_starts_with($ref, self::COMPONENT_REF)) {
            throw new GeneratorException(sprintf('Unsupported reference %s.', $ref));
        }
        $name = substr($ref, strlen(self::COMPONENT_REF));
        if (isset($this->components[$name])) {
            return $this->components[$name];
        }
        if (isset($this->componentsInProgress[$name])) {
            throw new GeneratorException(sprintf('%s refers to itself, which the generator does not support.', $ref));
        }
        $schema = $this->componentSchema($ref);
        $this->componentsInProgress[$name] = true;
        $type = $this->schema($schema, Naming::className(Naming::pascal($name)), 'components.schemas.' . $name . ': $');
        unset($this->componentsInProgress[$name]);

        return $this->components[$name] = $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function componentSchema(string $ref): array
    {
        $name = str_starts_with($ref, self::COMPONENT_REF) ? substr($ref, strlen(self::COMPONENT_REF)) : '';
        $schema = self::map(self::map(self::map($this->spec['components'] ?? [])['schemas'] ?? [])[$name] ?? null);
        if ($schema === []) {
            throw new GeneratorException(sprintf('%s does not exist.', $ref));
        }

        return $schema;
    }

    private function markRequest(Type $type): void
    {
        foreach ([$type->item, ...$type->items, ...$type->variants] as $inner) {
            if ($inner !== null) {
                $this->markRequest($inner);
            }
        }
        if ($type->kind === Type::UNION && isset($this->unions[$type->class])) {
            foreach ($this->unions[$type->class]->type()->variants as $variant) {
                $this->markRequest($variant);
            }
        }
        if ($type->kind !== Type::MODEL || !isset($this->models[$type->class]) || $this->models[$type->class]->request) {
            return;
        }
        $model = $this->models[$type->class];
        $model->request = true;
        foreach ($model->properties as $property) {
            $this->markRequest($property->type);
        }
    }

    /**
     * Reserves a model namespace class name. PHP compares class names
     * without case, and the runtime classes every model imports are taken.
     */
    private function claim(string $name, string $origin): void
    {
        $key = strtolower($name);
        if (isset($this->classes[$key])) {
            throw new GeneratorException(sprintf('%s and %s would both be class %s.', $this->classes[$key], $origin, $name));
        }
        if (Naming::isRuntimeClass($name)) {
            throw new GeneratorException(sprintf('%s would be class %s, which every model imports from the runtime.', $origin, $name));
        }
        $this->classes[$key] = $origin;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return string|list<string>
     */
    private static function inferType(array $schema): string|array
    {
        return match (true) {
            isset($schema['properties']) || isset($schema['additionalProperties']) => 'object',
            isset($schema['items']) || isset($schema['prefixItems']) => 'array',
            isset($schema['const']) => self::jsonType($schema['const']),
            isset($schema['enum']) => array_values(array_unique(array_map(self::jsonType(...), self::list($schema['enum'])))),
            default => [],
        };
    }

    private static function jsonType(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            is_array($value) && array_is_list($value) => 'array',
            default => 'object',
        };
    }

    private static function isSuccess(string $status): bool
    {
        return strlen($status) === 3 && $status[0] === '2';
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
