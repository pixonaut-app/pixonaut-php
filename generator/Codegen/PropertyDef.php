<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * One field of a model.
 */
final class PropertyDef
{
    /**
     * @param string                      $key         The JSON field name.
     * @param string                      $name        The PHP property name.
     * @param string|int|float|bool|null  $constant    The one value the field may take, checked on decoding
     *                                                 so a union can tell its variants apart.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly Type $type,
        public readonly bool $required,
        public readonly string $description,
        public readonly string|int|float|bool|null $constant = null,
    ) {}
}
