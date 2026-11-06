<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * One field of a model.
 */
final class PropertyDef
{
    /**
     * @param string $key  The JSON field name.
     * @param string $name The PHP property name.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly Type $type,
        public readonly bool $required,
        public readonly string $description,
    ) {}
}
