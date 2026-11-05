<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * A JSON object with named properties: one model class.
 */
final class ModelDef
{
    /** Outweighs any count of required fields. */
    private const CONSTANT_WEIGHT = 1000;

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

    /**
     * How much a union variant says about a value: one point per required
     * field, and a thousand per field that may take one value only.
     */
    public function specificity(): int
    {
        $score = 0;
        foreach ($this->properties as $property) {
            if ($property->required) {
                $score += $property->constant !== null ? self::CONSTANT_WEIGHT : 1;
            }
        }

        return $score;
    }
}
