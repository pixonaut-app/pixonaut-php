<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

use Pixonaut\Sdk\Webhooks\UnknownEvent;

/**
 * A `oneOf` or `anyOf`: one decoder class choosing among its variants.
 */
final class UnionDef
{
    public const ONE_OF = 'oneOf';
    public const ANY_OF = 'anyOf';
    public const DISCRIMINATED = 'discriminated';

    /**
     * @param list<array{Type, int, array<string, string|int|float|bool>}> $variants Each variant, its specificity
     *                                                                       and its tags, most specific first.
     * @param array<string, Type>    $mapping       Discriminator value → variant, for a discriminated union.
     * @param bool                   $events        A union of event envelopes, discriminated by `type`: a type
     *                                              this release does not know decodes to `UnknownEvent`.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly string $description,
        public readonly array $variants,
        public readonly string $origin,
        public readonly string $discriminator = '',
        public readonly array $mapping = [],
        public readonly bool $events = false,
    ) {}

    public function type(bool $nullable = false): Type
    {
        $variants = $this->kind === self::DISCRIMINATED
            ? array_values($this->mapping)
            : array_map(static fn(array $variant): Type => $variant[0], $this->variants);

        if ($this->events) {
            $variants[] = new Type(Type::MODEL, class: '\\' . UnknownEvent::class);
        }

        return new Type(Type::UNION, $nullable, class: $this->name, variants: $variants);
    }
}
