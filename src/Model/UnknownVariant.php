<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Model;

/**
 * An object of a variant this release does not know, added to the API after
 * it: the field that names the variant, its value, and the object as sent.
 * Code that does not handle the variant passes it by.
 */
final class UnknownVariant implements Model
{
    /**
     * @param string               $field  The field that names the variant, such as `kind`.
     * @param string               $value  Its value, which this release has no class for.
     * @param array<string, mixed> $object The object as received.
     */
    public function __construct(
        public readonly string $field,
        public readonly string $value,
        private readonly array $object,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->object;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->object;
    }
}
