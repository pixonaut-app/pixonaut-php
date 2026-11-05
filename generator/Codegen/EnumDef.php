<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * The values a string field is documented to take: one constants class.
 */
final class EnumDef
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $values,
        public readonly bool $extensible,
        public readonly string $origin,
    ) {}
}
