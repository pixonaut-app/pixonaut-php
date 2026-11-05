<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * The PHP shape of one schema: what a property holds.
 */
final class Type
{
    public const STRING = 'string';
    public const INT = 'int';
    public const FLOAT = 'float';
    public const BOOL = 'bool';
    public const NULL = 'null';
    public const MIXED = 'mixed';
    /** A JSON object with no properties described: `array<string, mixed>`. */
    public const OBJECT = 'object';
    public const LIST = 'list';
    public const MAP = 'map';
    public const TUPLE = 'tuple';
    public const MODEL = 'model';
    public const UNION = 'union';

    private const SCALARS = [self::STRING, self::INT, self::FLOAT, self::BOOL];

    /**
     * A union of more models than this is declared as the interface they
     * share; its PHPDoc type still names each one.
     */
    private const MAX_DECLARED_MODELS = 4;

    private const MODEL_INTERFACE = '\\Pixonaut\\Sdk\\Model\\Model';

    /**
     * @param Type|null  $item     A list's or a map's item; a tuple's items past `$items`.
     * @param list<Type> $items    A tuple's items.
     * @param string     $class    A model's or a union's class, without namespace.
     * @param string     $enum     The constants class naming a string's known values.
     * @param list<Type> $variants A union's variants.
     */
    public function __construct(
        public readonly string $kind,
        public readonly bool $nullable = false,
        public readonly ?Type $item = null,
        public readonly array $items = [],
        public readonly string $class = '',
        public readonly string $enum = '',
        public readonly array $variants = [],
    ) {}

    public function orNull(): self
    {
        if ($this->nullable || $this->kind === self::MIXED || $this->kind === self::NULL) {
            return $this;
        }

        return new self($this->kind, true, $this->item, $this->items, $this->class, $this->enum, $this->variants);
    }

    public function notNull(): self
    {
        return new self($this->kind, false, $this->item, $this->items, $this->class, $this->enum, $this->variants);
    }

    public function isScalar(): bool
    {
        return in_array($this->kind, self::SCALARS, true);
    }

    /**
     * Whether `null` is one of its values.
     */
    public function allowsNull(): bool
    {
        if ($this->nullable || $this->kind === self::MIXED || $this->kind === self::NULL) {
            return true;
        }
        foreach ($this->variants as $variant) {
            if ($variant->allowsNull()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The native declaration, e.g. `?string`, `array`, `A|B|null`.
     */
    public function native(): string
    {
        $parts = $this->nativeParts();
        // A lone `null` is no declared type before PHP 8.2.
        if (in_array('mixed', $parts, true) || $parts === ['null']) {
            return 'mixed';
        }
        if (count($parts) === 2 && in_array('null', $parts, true)) {
            $other = array_values(array_diff($parts, ['null']));

            return '?' . $other[0];
        }

        return implode('|', $parts);
    }

    /**
     * The PHPDoc type, e.g. `list<Item>|null`, `array<string, mixed>`.
     */
    public function doc(): string
    {
        $parts = $this->docParts();
        if (in_array('mixed', $parts, true)) {
            return 'mixed';
        }

        return implode('|', $parts);
    }

    /**
     * @return list<string>
     */
    private function nativeParts(): array
    {
        $parts = match ($this->kind) {
            self::NULL => ['null'],
            self::MIXED => ['mixed'],
            self::OBJECT, self::LIST, self::MAP, self::TUPLE => ['array'],
            self::MODEL => [$this->class],
            self::UNION => $this->manyModels()
                ? [self::MODEL_INTERFACE]
                : array_merge(...array_map(static fn(Type $variant): array => $variant->nativeParts(), $this->variants)),
            default => [$this->kind],
        };
        if ($this->nullable) {
            $parts[] = 'null';
        }

        return self::ordered($parts);
    }

    private function manyModels(): bool
    {
        foreach ($this->variants as $variant) {
            if ($variant->kind !== self::MODEL || $variant->nullable) {
                return false;
            }
        }

        return count($this->variants) > self::MAX_DECLARED_MODELS;
    }

    /**
     * @return list<string>
     */
    private function docParts(): array
    {
        $parts = match ($this->kind) {
            self::NULL => ['null'],
            self::MIXED => ['mixed'],
            self::OBJECT => ['array<string, mixed>'],
            self::LIST => ['list<' . ($this->item ?? new self(self::MIXED))->doc() . '>'],
            self::MAP => ['array<string, ' . ($this->item ?? new self(self::MIXED))->doc() . '>'],
            self::TUPLE => ['list<' . $this->tupleItemDoc() . '>'],
            self::MODEL => [$this->class],
            self::UNION => array_merge(...array_map(static fn(Type $variant): array => $variant->docParts(), $this->variants)),
            default => [$this->kind],
        };
        if ($this->nullable) {
            $parts[] = 'null';
        }

        return self::ordered($parts);
    }

    private function tupleItemDoc(): string
    {
        $items = $this->items;
        if ($this->item !== null) {
            $items[] = $this->item;
        }
        $docs = [];
        foreach ($items as $item) {
            foreach ($item->docParts() as $part) {
                $docs[] = $part;
            }
        }

        return $docs === [] ? 'mixed' : (in_array('mixed', $docs, true) ? 'mixed' : implode('|', self::ordered($docs)));
    }

    /**
     * Unique, with `null` last, in first-seen order otherwise.
     *
     * @param list<string> $parts
     *
     * @return list<string>
     */
    private static function ordered(array $parts): array
    {
        $unique = array_values(array_unique($parts));
        $withoutNull = array_values(array_filter($unique, static fn(string $part): bool => $part !== 'null'));
        if (count($withoutNull) !== count($unique)) {
            $withoutNull[] = 'null';
        }

        return $withoutNull;
    }
}
