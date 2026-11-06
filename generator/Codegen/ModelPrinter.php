<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\Sdk\Model\Decode;
use Pixonaut\Sdk\Model\Encode;
use Pixonaut\Sdk\Model\Model;
use Pixonaut\Sdk\Model\Omitted;

/**
 * Prints models, union decoders and enum constants classes.
 */
final class ModelPrinter
{
    /** The private property a model keeps the decoded object in. */
    private const RAW = 'decoded';

    /** The class constant listing every value of an enum. */
    private const KNOWN_VALUES = 'KNOWN';

    /**
     * @param array<string, ModelDef> $models
     * @param array<string, UnionDef> $unions
     */
    public function __construct(
        private readonly string $namespace,
        private readonly array $models = [],
        private readonly array $unions = [],
    ) {}

    public function modelNamespace(): string
    {
        return $this->namespace . '\\Model';
    }

    public function enumNamespace(): string
    {
        return $this->namespace . '\\Enum';
    }

    public function model(ModelDef $model): string
    {
        $properties = [...array_filter($model->properties, static fn(PropertyDef $p): bool => $p->required),
            ...array_filter($model->properties, static fn(PropertyDef $p): bool => !$p->required)];
        foreach ($properties as $property) {
            if ($property->name === self::RAW) {
                throw new GeneratorException(sprintf('%s: field "%s" clashes with the property a model keeps its decoded object in.', $model->origin, $property->key));
            }
        }

        $params = [];
        $docs = [];
        $args = [];
        foreach ($properties as $property) {
            [$native, $doc, $default] = $this->declaration($model, $property);
            $param = new Node\Param(Ast::var($property->name), $default, Ast::type($native), flags: Modifiers::PUBLIC | Modifiers::READONLY);
            $params[] = $param;
            $text = $this->propertyText($property);
            if ($text !== '' || $doc !== $native) {
                $docs[] = '@param ' . $doc . ' $' . $property->name . $text;
            }
            $args[] = Ast::named($property->name, $this->field($model, $property));
        }

        $constructor = new Stmt\ClassMethod('__construct', [
            'flags' => Modifiers::PUBLIC,
            'params' => $params,
            'stmts' => [],
        ]);
        if ($docs !== []) {
            $constructor->setDocComment(self::requireDoc($docs));
        }

        $fromArray = new Stmt\ClassMethod('fromArray', [
            'flags' => Modifiers::PUBLIC | Modifiers::STATIC,
            'params' => [
                new Node\Param(Ast::var('data'), null, new Identifier('array')),
                new Node\Param(Ast::var('path'), Ast::string('$'), new Identifier('string')),
            ],
            'returnType' => new Name('self'),
            'stmts' => [
                new Stmt\Expression(new Expr\Assign(Ast::var('model'), new Expr\New_(new Name('self'), $args))),
                new Stmt\Expression(new Expr\Assign(new Expr\PropertyFetch(Ast::var('model'), self::RAW), Ast::var('data'))),
                new Stmt\Return_(Ast::var('model')),
            ],
        ]);
        $fromArray->setDocComment(self::requireDoc([
            'Decodes the object, checking every field this release knows.',
            '',
            '@param array<string, mixed> $data',
            '@param string $path Where the object is in the document, for the error.',
            '',
            '@throws UnexpectedResponse When a required field is missing or a field has the wrong type.',
        ]));

        $raw = new Stmt\ClassMethod('raw', [
            'flags' => Modifiers::PUBLIC,
            'returnType' => new Identifier('array'),
            'stmts' => [new Stmt\Return_(new Expr\BinaryOp\Coalesce(Ast::this(self::RAW), new Expr\MethodCall(Ast::var('this'), 'toArray')))],
        ]);
        $raw->setDocComment(self::requireDoc(['@return array<string, mixed>']));

        $toArray = new Stmt\ClassMethod('toArray', [
            'flags' => Modifiers::PUBLIC,
            'returnType' => new Identifier('array'),
            'stmts' => $this->toArrayBody($model, $properties),
        ]);
        $toArray->setDocComment(self::requireDoc(['@return array<string, mixed>']));

        $rawProperty = new Stmt\Property(Modifiers::PRIVATE, [new Node\PropertyItem(self::RAW, Ast::null())], [], new Node\NullableType(new Identifier('array')));
        $rawProperty->setDocComment(self::requireDoc(['@var array<string, mixed>|null The object as decoded, unknown fields included.']));

        $class = new Stmt\Class_($model->name, [
            'flags' => Modifiers::FINAL,
            'implements' => [new Name('Model')],
            'stmts' => [$rawProperty, $constructor, $fromArray, $raw, $toArray],
        ]);
        $class->setDocComment(self::requireDoc(self::classText($model->description, $model->origin)));

        $uses = [Decode::class, Model::class, UnexpectedResponse::class];
        if ((new NodeFinder())->findFirst($toArray, static fn(Node $node): bool => $node instanceof Expr\StaticCall && $node->class instanceof Name && $node->class->toString() === 'Encode') !== null) {
            $uses[] = Encode::class;
        }
        if ($model->request) {
            foreach ($properties as $property) {
                if (!$property->required && $property->type->allowsNull()) {
                    $uses[] = Omitted::class;
                    break;
                }
            }
        }

        return Ast::file($this->modelNamespace(), $uses, $class);
    }

    public function union(UnionDef $union): string
    {
        $type = $union->type();
        $path = Ast::var('path');
        $data = Ast::var('data');
        if ($union->kind === UnionDef::DISCRIMINATED) {
            $tag = Ast::var('tag');
            $arms = [];
            foreach ($union->mapping as $value => $variant) {
                $arms[] = new Node\MatchArm([Ast::string($value)], $this->decode($variant, $data, $path));
            }
            $arms[] = new Node\MatchArm(null, Ast::call('Decode', 'unknownVariant', [$path, Ast::string($union->discriminator), $tag]));
            $statements = [
                new Stmt\Expression(new Expr\Assign($tag, Ast::call('Decode', 'discriminator', [$data, $path, Ast::string($union->discriminator)]))),
                new Stmt\Return_(new Expr\Match_($tag, $arms)),
            ];
            $text = sprintf('Decodes the variant its `%s` names.', $union->discriminator);
        } else {
            $variants = [];
            foreach ($union->variants as [$variant, $specificity, $tags]) {
                $variants[] = Ast::list([
                    Ast::decoder($variant->native(), $this->decode($variant, Ast::var('v'), Ast::var('p'))),
                    new Node\Scalar\Int_($specificity),
                    Ast::value($tags),
                ]);
            }
            $statements = [new Stmt\Return_(Ast::call('Decode', $union->kind, [$data, $path, Ast::list($variants)]))];
            $text = $union->kind === UnionDef::ONE_OF
                ? 'Decodes the one variant the value matches, the most specific first.'
                : 'Decodes the first variant the value matches, the most specific first.';
        }

        $decode = new Stmt\ClassMethod('decode', [
            'flags' => Modifiers::PUBLIC | Modifiers::STATIC,
            'params' => [
                new Node\Param($data, null, new Identifier('mixed')),
                new Node\Param($path, Ast::string('$'), new Identifier('string')),
            ],
            'returnType' => Ast::type($type->native()),
            'stmts' => $statements,
        ]);
        $decode->setDocComment(self::requireDoc([
            $text,
            '',
            '@param string $path Where the value is in the document, for the error.',
            '',
            '@return ' . $type->doc(),
            '',
            '@throws UnexpectedResponse When no variant matches, or a `oneOf` matches two.',
        ]));

        $class = new Stmt\Class_($union->name, [
            'flags' => Modifiers::FINAL,
            'stmts' => [$decode],
        ]);
        $class->setDocComment(self::requireDoc(self::classText($union->description, $union->origin)));

        return Ast::file($this->modelNamespace(), [Decode::class, UnexpectedResponse::class], $class);
    }

    public function enum(EnumDef $enum): string
    {
        $constants = [];
        $names = [];
        foreach ($enum->values as $value) {
            $name = Naming::constant($value);
            if (isset($names[$name]) || $name === self::KNOWN_VALUES) {
                throw new GeneratorException(sprintf('%s: values "%s" and "%s" are both constant %s.', $enum->origin, $names[$name] ?? self::KNOWN_VALUES, $value, $name));
            }
            $names[$name] = $value;
            $constants[] = new Stmt\ClassConst([new Node\Const_($name, Ast::string($value))], Modifiers::PUBLIC);
        }
        $known = new Stmt\ClassConst([new Node\Const_(self::KNOWN_VALUES, Ast::list(array_map(
            static fn(string $name): Expr => Ast::constant('self', $name),
            array_keys($names),
        )))], Modifiers::PUBLIC);
        $known->setDocComment(self::requireDoc(['Every value this release knows.']));

        $class = new Stmt\Class_($enum->name, [
            'flags' => Modifiers::FINAL,
            'stmts' => [...$constants, $known],
        ]);
        $class->setDocComment(self::requireDoc([
            ...self::classText($enum->description, ''),
            $enum->extensible
                ? 'The values this release knows. The field is a string, and the API may answer a value added after this release.'
                : 'The values the spec lists. The field is a string, so a value added in a later API release still decodes.',
            '',
            'Schema: `' . $enum->origin . '`.',
        ]));

        return Ast::file($this->enumNamespace(), [], $class);
    }

    /**
     * The native type, PHPDoc type and default of a constructor parameter.
     *
     * @return array{string, string, Expr|null}
     */
    private function declaration(ModelDef $model, PropertyDef $property): array
    {
        $type = $property->type;
        if ($property->required) {
            return [$type->native(), $type->doc(), null];
        }
        if ($model->request && $type->allowsNull()) {
            $native = $type->native();

            return [
                $native === 'mixed' ? 'mixed' : (str_starts_with($native, '?') ? substr($native, 1) . '|null' : $native) . '|Omitted',
                $type->doc() . '|Omitted',
                Ast::constant('Omitted', 'Field'),
            ];
        }
        $nullable = $type->orNull();

        return [$nullable->native(), $nullable->doc(), Ast::null()];
    }

    private function propertyText(PropertyDef $property): string
    {
        $text = $property->description;
        $enum = $property->type->enum !== '' ? $property->type->enum : (($property->type->item->enum ?? ''));
        if ($enum !== '') {
            $text .= ($text === '' ? '' : "\n") . 'Values: {@see \\' . $this->enumNamespace() . '\\' . $enum . '}.';
        }
        if ($text === '') {
            return '';
        }

        return ' ' . str_replace("\n", "\n    ", $text);
    }

    /**
     * The argument decoding one field in `fromArray()`.
     */
    private function field(ModelDef $model, PropertyDef $property): Expr
    {
        $path = new Expr\BinaryOp\Concat(Ast::var('path'), Ast::string(self::pathSuffix($property->key)));
        $key = Ast::string($property->key);
        if ($property->required) {
            $value = Ast::call('Decode', 'required', [Ast::var('data'), $key, Ast::var('path')]);

            return $this->decode($property->type, $value, $path);
        }
        $default = $model->request && $property->type->allowsNull() ? Ast::constant('Omitted', 'Field') : Ast::null();

        return new Expr\Ternary(
            Ast::func('array_key_exists', [$key, Ast::var('data')]),
            $this->decode($property->type, new Expr\ArrayDimFetch(Ast::var('data'), $key), $path),
            $default,
        );
    }

    /**
     * The expression decoding `$value`, found at `$path`, as `$type`.
     */
    private function decode(Type $type, Expr $value, Expr $path): Expr
    {
        if ($type->nullable && $type->kind !== Type::MIXED && $type->kind !== Type::NULL) {
            $inner = $type->notNull();

            return Ast::call('Decode', 'nullable', [$value, $path, Ast::decoder($inner->native(), $this->decode($inner, Ast::var('v'), Ast::var('p')))]);
        }

        return match ($type->kind) {
            Type::STRING => Ast::call('Decode', 'string', [$value, $path]),
            Type::INT => Ast::call('Decode', 'int', [$value, $path]),
            Type::FLOAT => Ast::call('Decode', 'float', [$value, $path]),
            Type::BOOL => Ast::call('Decode', 'bool', [$value, $path]),
            Type::NULL => Ast::call('Decode', 'null', [$value, $path]),
            Type::MIXED => $value,
            Type::OBJECT => Ast::call('Decode', 'object', [$value, $path]),
            Type::MODEL => Ast::call($type->class, 'fromArray', [Ast::call('Decode', 'object', [$value, $path]), $path]),
            Type::UNION => Ast::call($type->class, 'decode', [$value, $path]),
            Type::LIST => Ast::call('Decode', 'list', [$value, $path, $this->itemDecoder($type->item)]),
            Type::MAP => Ast::call('Decode', 'map', [$value, $path, $this->itemDecoder($type->item)]),
            Type::TUPLE => Ast::call('Decode', 'tuple', [
                $value,
                $path,
                Ast::list(array_map($this->itemDecoder(...), $type->items)),
                ...($type->item === null ? [] : [$this->itemDecoder($type->item)]),
            ]),
            default => throw new GeneratorException('Unknown type ' . $type->kind),
        };
    }

    private function itemDecoder(?Type $item): Expr
    {
        $item ??= new Type(Type::MIXED);

        return Ast::decoder($item->native(), $this->decode($item, Ast::var('v'), Ast::var('p')));
    }

    /**
     * @param list<PropertyDef> $properties
     *
     * @return list<Stmt>
     */
    private function toArrayBody(ModelDef $model, array $properties): array
    {
        $statements = [new Stmt\Expression(new Expr\Assign(Ast::var('data'), new Expr\Array_([])))];
        foreach ($properties as $property) {
            $target = new Expr\ArrayDimFetch(Ast::var('data'), Ast::string($property->key));
            $value = Ast::this($property->name);
            if ($property->required) {
                $statements[] = new Stmt\Expression(new Expr\Assign($target, $this->encode($property->type, $value)));
                continue;
            }
            $omittable = $model->request && $property->type->allowsNull();
            // Left out when unset: past the check, a field that defaults to `null` is not null.
            $assign = new Stmt\Expression(new Expr\Assign($target, $this->encode($omittable ? $property->type : $property->type->notNull(), $value)));
            $condition = $omittable
                ? new Expr\BooleanNot(new Expr\Instanceof_($value, new Name('Omitted')))
                : new Expr\BinaryOp\NotIdentical($value, Ast::null());
            $statements[] = new Stmt\If_($condition, ['stmts' => [$assign]]);
        }
        $statements[] = new Stmt\Return_(Ast::var('data'));

        return $statements;
    }

    /**
     * The expression turning `$value`, of `$type`, into what a request
     * sends: an object stays an object at every level, even when empty.
     */
    private function encode(Type $type, Expr $value): Expr
    {
        if ($type->nullable && $type->kind !== Type::MIXED && $type->kind !== Type::NULL) {
            $encoded = $this->encode($type->notNull(), $value);

            return $encoded === $value ? $value : new Expr\Ternary(new Expr\BinaryOp\Identical($value, Ast::null()), Ast::null(), $encoded);
        }

        return match ($type->kind) {
            Type::OBJECT => $this->encodeCall('object', [$value]),
            Type::MODEL => $this->encodeCall('model', [$value]),
            Type::LIST => $this->encodeItems($type->item, $value),
            Type::MAP => $this->encodeCall('object', [$this->encodeItems($type->item, $value)]),
            Type::TUPLE => $this->tupleIsPlain($type) ? $value : $this->encodeCall('value', [$value]),
            Type::UNION => $this->encodeCall('value', $this->emptyIsObject($type) ? [$value, Ast::value(true)] : [$value]),
            default => $value,
        };
    }

    private function encodeItems(?Type $item, Expr $value): Expr
    {
        $item ??= new Type(Type::MIXED);
        $element = Ast::var('v');
        $encoded = $this->encode($item, $element);
        if ($encoded === $element) {
            return $value;
        }

        return Ast::func('array_map', [
            new Expr\ArrowFunction([
                'static' => true,
                'params' => [new Node\Param($element, null, Ast::type($item->native()))],
                'returnType' => new Identifier('mixed'),
                'expr' => $encoded,
            ]),
            $value,
        ]);
    }

    private function tupleIsPlain(Type $type): bool
    {
        foreach ([...$type->items, ...($type->item === null ? [] : [$type->item])] as $item) {
            $element = Ast::var('v');
            if ($this->encode($item, $element) !== $element) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a union decodes an empty JSON container as an object: then an
     * empty array it holds is sent as `{}`.
     */
    private function emptyIsObject(Type $type): bool
    {
        $union = $this->unions[$type->class] ?? null;
        if ($union === null || $union->kind === UnionDef::DISCRIMINATED) {
            return false;
        }
        foreach ($union->variants as [$variant, , $tags]) {
            if ($variant->nullable || $tags !== []) {
                continue;
            }
            switch ($variant->kind) {
                case Type::OBJECT:
                case Type::MAP:
                    return true;
                case Type::MODEL:
                    if ($this->acceptsEmpty($variant->class)) {
                        return true;
                    }
                    break;
                case Type::UNION:
                    return $this->emptyIsObject($variant);
                case Type::LIST:
                case Type::TUPLE:
                case Type::MIXED:
                    return false;
            }
        }

        return false;
    }

    private function acceptsEmpty(string $model): bool
    {
        foreach ($this->models[$model]->properties ?? [] as $property) {
            if ($property->required) {
                return false;
            }
        }

        return isset($this->models[$model]);
    }

    /**
     * @param list<Expr> $args
     */
    private function encodeCall(string $method, array $args): Expr
    {
        return Ast::call('Encode', $method, $args);
    }

    /**
     * @return list<string>
     */
    private static function classText(string $description, string $origin): array
    {
        $lines = $description === '' ? [] : [$description, ''];
        if ($origin !== '') {
            $lines[] = 'Schema: `' . $origin . '`.';
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private static function requireDoc(array $lines): \PhpParser\Comment\Doc
    {
        return Ast::doc($lines) ?? throw new GeneratorException('Empty doc comment.');
    }

    /**
     * What a field adds to its object's path, as `Decode::field()` spells it.
     */
    private static function pathSuffix(string $key): string
    {
        return Decode::field('', $key);
    }
}
