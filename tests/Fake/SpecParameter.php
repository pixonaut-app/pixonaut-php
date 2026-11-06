<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Fake;

use stdClass;

/**
 * One parameter of a spec operation.
 */
final class SpecParameter
{
    /**
     * @param string       $in      `path`, `query` or `header`.
     * @param list<string> $pointer Where its schema is in the spec.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $in,
        public readonly bool $required,
        public readonly array $pointer,
        public readonly stdClass $schema,
    ) {}
}
