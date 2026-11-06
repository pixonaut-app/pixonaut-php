<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * A JSON object with named properties: one model class.
 */
final class ModelDef
{
    /**
     * Whether a request sends it. A request model tells an optional field
     * left out from one set to `null`.
     */
    public bool $request = false;

    /**
     * @param list<PropertyDef> $properties
     * @param string            $origin     Where in the spec it is defined, for collision messages.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $properties,
        public readonly string $origin,
    ) {}
}
