<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

use InvalidArgumentException;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\Sdk\Http\Binary;
use Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\Sdk\Model\Decode;
use Pixonaut\Sdk\Pixonaut;

/**
 * Prints a class per resource, one method per operation, and the
 * `Resources` trait that reaches them from `Pixonaut`.
 */
final class ResourcePrinter
{
    private const RESOURCES_TRAIT = 'Resources';

    /** The variable a method's answer is held in. */
    private const RESULT = '$result';

    /** The parameter an idempotent operation takes its key in. */
    private const KEY = 'idempotencyKey';

    private const BODY = 'body';

    private readonly CodePrinter $printer;

    /** @var list<string> Model and union classes the resource being printed names. */
    private array $models = [];

    public function __construct(
        private readonly string $namespace,
        private readonly ModelPrinter $modelPrinter,
    ) {
        $this->printer = new CodePrinter();
    }

    public function resourceNamespace(): string
    {
        return $this->namespace . '\\Resource';
    }

    /**
     * @param list<ResourceDef> $resources
     */
    public function trait(array $resources): string
    {
        $methods = [];
        foreach ($resources as $resource) {
            $method = new Stmt\ClassMethod($resource->name, [
                'flags' => Modifiers::PUBLIC,
                'returnType' => new Name($resource->class),
                'stmts' => [new Stmt\Return_(new Expr\New_(new Name($resource->class), [new Node\Arg(Ast::var('this'))]))],
            ]);
            $method->setDocComment(self::doc(['The ' . self::title($resource) . ' operations.']));
            $methods[] = $method;
        }
        $trait = new Stmt\Trait_(self::RESOURCES_TRAIT, ['stmts' => $methods]);
        $trait->setDocComment(self::doc(['The resources `Pixonaut` reaches, each holding a method per operation.']));

        return Ast::file(
            $this->namespace,
            array_map(fn(ResourceDef $resource): string => $this->resourceNamespace() . '\\' . $resource->class, $resources),
            $trait,
        );
    }

    public function resource(ResourceDef $resource): string
    {
        $this->models = [];
        $uses = [Pixonaut::class, ApiException::class];
        $methods = [];
        $index = [];
        foreach ($resource->methods as $name => $operation) {
            $index[$name] = $operation->id;
            foreach ($this->methods($name, $operation, $uses) as $method) {
                $methods[] = $method;
            }
        }

        $operations = new Stmt\ClassConst([new Node\Const_('OPERATIONS', Ast::value($index))], Modifiers::PUBLIC);
        $operations->setDocComment(self::doc(['Each method, with the operation it sends.', '', '@var array<string, string>']));
        $constructor = new Stmt\ClassMethod('__construct', [
            'flags' => Modifiers::PUBLIC,
            'params' => [new Node\Param(Ast::var('pixonaut'), null, new Name('Pixonaut'), flags: Modifiers::PRIVATE | Modifiers::READONLY)],
        ]);
        $class = new Stmt\Class_($resource->class, [
            'flags' => Modifiers::FINAL,
            'stmts' => [$operations, $constructor, ...$methods],
        ]);
        $class->setDocComment(self::doc(['The ' . self::title($resource) . ' operations, reached as `$pixonaut->' . $resource->name . '()`.']));

        foreach (array_unique($this->models) as $model) {
            $uses[] = $this->modelPrinter->modelNamespace() . '\\' . $model;
        }

        return Ast::file($this->resourceNamespace(), $uses, $class);
    }

    /**
     * The method sending the operation; for an idempotent one also the
     * `prepare…()` and `send…()` pair it is built from.
     *
     * @param list<string> $uses
     *
     * @return list<Stmt\ClassMethod>
     */
    private function methods(string $name, OperationDef $operation, array &$uses): array
    {
        $parameters = $this->parameters($operation);
        [$returnType, $returnStatements] = $this->answer($operation, $uses);
        $returns = $returnType->native() !== 'void';
        $declared = implode(', ', array_map(static fn(array $parameter): string => $parameter[0], $parameters));
        $docs = array_map(static fn(array $parameter): string => $parameter[1], $parameters);
        $arguments = implode(', ', array_map(static fn(array $parameter): string => '$' . $parameter[2], $parameters));
        $request = $this->request($operation);
        $throws = $this->throws($operation);
        $summary = $operation->summary !== '' ? $operation->summary : $operation->id;
        $summary .= preg_match('/[.!?]\z/', $summary) === 1 ? '' : '.';
        $route = '`' . $operation->method . ' ' . $operation->path . '`';

        if (!$operation->idempotent) {
            $code = sprintf(
                "public function %s(%s): %s\n{\n%s = \$this->pixonaut->call(%s);\n%s\n}",
                $name,
                $declared,
                $returnType->native(),
                self::RESULT,
                $request,
                $returnStatements,
            );
            if (!$returns) {
                $code = sprintf("public function %s(%s): void\n{\n\$this->pixonaut->call(%s);\n}", $name, $declared, $request);
            }

            return [$this->method($code, [$summary, '', $route, '', ...$docs, ...$this->returnDoc($returnType), '', ...$throws])];
        }

        $uses[] = PreparedRequest::class;
        $uses[] = InvalidArgumentException::class;
        $prepare = 'prepare' . ucfirst($name);
        $send = 'send' . ucfirst($name);
        $call = sprintf('$this->%s($this->%s(%s))', $send, $prepare, $arguments);
        $main = sprintf(
            "public function %s(%s): %s\n{\n%s;\n}",
            $name,
            $declared,
            $returnType->native(),
            $returns ? 'return ' . $call : $call,
        );
        $prepared = sprintf("public function %s(%s): PreparedRequest\n{\nreturn \$this->pixonaut->prepare(%s);\n}", $prepare, $declared, $request);
        $sent = $returns
            ? sprintf(
                "public function %s(PreparedRequest \$request): %s\n{\n%s = \$this->pixonaut->send(\$request->expect(%s));\n%s\n}",
                $send,
                $returnType->native(),
                self::RESULT,
                var_export($operation->id, true),
                $returnStatements,
            )
            : sprintf(
                "public function %s(PreparedRequest \$request): void\n{\n\$this->pixonaut->send(\$request->expect(%s));\n}",
                $send,
                var_export($operation->id, true),
            );

        return [
            $this->method($main, [
                $summary,
                '',
                $route . ', with an `Idempotency-Key`: a repeat with the same key is answered with what the first one made.',
                'To send the same request again later, keep {@see ' . $prepare . '()} and send it with {@see ' . $send . '()}.',
                '',
                ...$docs,
                ...$this->returnDoc($returnType),
                '',
                ...$throws,
                '@throws InvalidArgumentException When the request does not fit the operation; nothing is sent.',
            ]),
            $this->method($prepared, [
                'The ' . $route . ' request {@see ' . $name . '()} sends, body encoded and key chosen, for {@see ' . $send . '()}.',
                'Store its `toArray()` before sending, and a retry sends the same bytes under the same key.',
                '',
                ...$docs,
                '',
                '@throws InvalidArgumentException When the request does not fit the operation.',
            ]),
            $this->method($sent, [
                'Sends a request from {@see ' . $prepare . '()}, as prepared or restored with `PreparedRequest::fromArray()`.',
                '',
                ...$this->returnDoc($returnType),
                '',
                ...$throws,
                '@throws InvalidArgumentException When the request was prepared for another operation, or does not fit this one.',
            ]),
        ];
    }

    /**
     * Each parameter as its declaration, its `@param` line and its variable:
     * path parameters, required query parameters, the body, optional query
     * parameters, headers, then the idempotency key.
     *
     * @return list<array{string, string, string}>
     */
    private function parameters(OperationDef $operation): array
    {
        $groups = ['path' => [], 'required' => [], 'body' => [], 'optionalBody' => [], 'optional' => [], 'header' => [], 'key' => []];
        foreach ($operation->parameters as $parameter) {
            $group = match (true) {
                $parameter->in === ParameterDef::PATH => 'path',
                $parameter->in === ParameterDef::HEADER => 'header',
                $parameter->required => 'required',
                default => 'optional',
            };
            if ($parameter->in === ParameterDef::HEADER && $parameter->type->kind !== Type::STRING) {
                throw new GeneratorException(sprintf('%s: header %s is not a string.', $operation->id, $parameter->name));
            }
            $groups[$group][] = $this->declare($parameter->variable, $parameter->type, $parameter->required, $parameter->description);
        }
        if ($operation->request !== null) {
            // A body that may be `null` is one that may be left out.
            $body = $operation->request->notNull();
            if (!self::isObject($body)) {
                throw new GeneratorException(sprintf('%s: a request body that is not an object is not supported.', $operation->id));
            }
            $required = $operation->requestRequired && !$operation->request->nullable;
            $groups[$required ? 'body' : 'optionalBody'][] = $this->declare(self::BODY, $body, $required, '');
        }
        if ($operation->idempotent) {
            $groups['key'][] = $this->declare(self::KEY, new Type(Type::STRING), false, 'Your key for the request; a new UUID when null.');
        }
        $parameters = array_merge(...array_values($groups));
        $variables = array_map(static fn(array $parameter): string => $parameter[2], $parameters);
        if (count($variables) !== count(array_unique($variables))) {
            throw new GeneratorException(sprintf('%s: two parameters would both be named $%s.', $operation->id, implode(', $', array_diff_assoc($variables, array_unique($variables)))));
        }

        return $parameters;
    }

    /**
     * @return array{string, string, string}
     */
    private function declare(string $variable, Type $type, bool $required, string $description): array
    {
        $this->collectModels($type);
        $type = $required ? $type : $type->orNull();
        $declaration = $type->native() . ' $' . $variable . ($required ? '' : ' = null');
        $text = trim($description) === '' ? '' : ' ' . str_replace("\n", "\n    ", trim($description));

        return [$declaration, '@param ' . $type->doc() . ' $' . $variable . $text, $variable];
    }

    /**
     * The arguments to `call()` or `prepare()`.
     */
    private function request(OperationDef $operation): string
    {
        $arguments = [var_export($operation->id, true)];
        $groups = [ParameterDef::PATH => [], ParameterDef::QUERY => [], ParameterDef::HEADER => []];
        foreach ($operation->parameters as $parameter) {
            $groups[$parameter->in][] = var_export($parameter->name, true) . ' => $' . $parameter->variable;
        }
        foreach ([ParameterDef::PATH => 'path', ParameterDef::QUERY => 'query'] as $in => $argument) {
            if ($groups[$in] !== []) {
                $arguments[] = $argument . ': [' . implode(', ', $groups[$in]) . ']';
            }
        }
        if ($operation->request !== null) {
            $arguments[] = 'body: $body' . ($operation->requestRequired && !$operation->request->nullable ? '->' : '?->') . 'toArray()';
        }
        if ($operation->idempotent) {
            $arguments[] = 'idempotencyKey: $' . self::KEY;
        }
        if ($groups[ParameterDef::HEADER] !== []) {
            $arguments[] = 'headers: [' . implode(', ', $groups[ParameterDef::HEADER]) . ']';
        }

        return implode(', ', $arguments);
    }

    /**
     * What the method answers, and the statements turning `$result` into it.
     *
     * @param list<string> $uses
     *
     * @return array{Type, string}
     */
    private function answer(OperationDef $operation, array &$uses): array
    {
        if ($operation->binaryStatuses !== []) {
            if ($operation->responses !== [] || $operation->emptyStatuses !== []) {
                throw new GeneratorException(sprintf('%s answers both a file and something else, which is not supported.', $operation->id));
            }
            $uses[] = Binary::class;

            return [new Type(Type::MODEL, class: 'Binary'), 'return ' . self::RESULT . '->binary();'];
        }
        if ($operation->responses === []) {
            return [new Type('void'), ''];
        }

        $uses[] = Decode::class;
        $uses[] = UnexpectedResponse::class;
        /** @var array<string, array{Type, list<int>}> $byType */
        $byType = [];
        foreach ($operation->responses as $status => $type) {
            $this->collectModels($type);
            $byType[$type->doc()][0] = $type;
            $byType[$type->doc()][1][] = $status;
        }
        $types = array_map(static fn(array $entry): Type => $entry[0], array_values($byType));
        $type = count($types) === 1 ? $types[0] : new Type(Type::UNION, variants: $types);
        if ($operation->emptyStatuses !== []) {
            $type = $type->orNull();
        }

        if (count($byType) === 1 && $operation->emptyStatuses === []) {
            return [$type, 'return ' . $this->decodeText($types[0]) . ';'];
        }
        $arms = [];
        foreach ($byType as [$armType, $statuses]) {
            $arms[] = implode(', ', $statuses) . ' => ' . $this->decodeText($armType) . ',';
        }
        if ($operation->emptyStatuses !== []) {
            $arms[] = implode(', ', $operation->emptyStatuses) . ' => null,';
        }
        $arms[] = 'default => throw ' . self::RESULT . '->unexpected(),';

        return [$type, "return match (" . self::RESULT . "->status) {\n" . implode("\n", $arms) . "\n};"];
    }

    private function decodeText(Type $type): string
    {
        return $this->printer->prettyPrintExpr($this->modelPrinter->decode(
            $type,
            new Expr\MethodCall(Ast::var('result'), 'json'),
            Ast::string('$'),
        ));
    }

    /**
     * @return list<string>
     */
    private function returnDoc(Type $type): array
    {
        $native = $type->native();
        if ($native === 'void' || $type->doc() === $native) {
            return [];
        }

        return ['', '@return ' . $type->doc()];
    }

    /**
     * @return list<string>
     */
    private function throws(OperationDef $operation): array
    {
        $codes = $operation->errorCodes === []
            ? ''
            : ' (documented: `' . implode('`, `', $operation->errorCodes) . '`)';
        $throws = ['@throws ApiException When Pixonaut refuses the request' . $codes . ', or cannot be reached.'];
        if ($operation->responses !== []) {
            $throws[] = '@throws UnexpectedResponse When the answer does not have the shape this release knows.';
        }

        return $throws;
    }

    /**
     * @param list<string> $lines
     */
    private function method(string $code, array $lines): Stmt\ClassMethod
    {
        $parsed = (new ParserFactory())->createForHostVersion()->parse("<?php\nclass Resource\n{\n" . $code . "\n}\n");
        $class = $parsed[0] ?? null;
        if (!$class instanceof Stmt\Class_ || !($class->stmts[0] ?? null) instanceof Stmt\ClassMethod) {
            throw new GeneratorException('Cannot print the method ' . strtok($code, "\n") . '.');
        }
        $method = $class->stmts[0];
        $method->setDocComment(self::doc($lines));

        return $method;
    }

    /**
     * Whether the type is a model, or a union of models: something with `toArray()`.
     */
    private static function isObject(Type $type): bool
    {
        if ($type->nullable) {
            return false;
        }
        if ($type->kind === Type::UNION) {
            return array_filter($type->variants, static fn(Type $variant): bool => !self::isObject($variant)) === [];
        }

        return $type->kind === Type::MODEL;
    }

    private function collectModels(Type $type): void
    {
        if (($type->kind === Type::MODEL || $type->kind === Type::UNION) && $type->class !== '' && $type->class !== 'Binary') {
            $this->models[] = $type->class;
        }
        foreach ([$type->item, ...$type->items, ...$type->variants] as $inner) {
            if ($inner !== null) {
                $this->collectModels($inner);
            }
        }
    }

    /**
     * `shareLinks` → `share links`.
     */
    private static function title(ResourceDef $resource): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $resource->name));
    }

    /**
     * @param list<string> $lines
     */
    private static function doc(array $lines): \PhpParser\Comment\Doc
    {
        // Blank lines in a row, as an empty section leaves them, read as one.
        $collapsed = [];
        foreach ($lines as $line) {
            if ($line === '' && ($collapsed === [] || end($collapsed) === '')) {
                continue;
            }
            $collapsed[] = $line;
        }

        return Ast::doc($collapsed) ?? throw new GeneratorException('Empty doc comment.');
    }
}
