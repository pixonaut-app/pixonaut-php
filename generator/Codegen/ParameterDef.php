<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * One path, query or header parameter of an operation.
 */
final class ParameterDef
{
    public const PATH = 'path';
    public const QUERY = 'query';
    public const HEADER = 'header';

    /**
     * @param string $name        As the spec names it, e.g. `pageSize`, `prefer`.
     * @param string $in          `path`, `query` or `header`.
     * @param string $variable    The PHP parameter name, e.g. `pageSize`, `session`.
     * @param Type   $type        What it takes, without the `null` an optional one adds.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $in,
        public readonly string $variable,
        public readonly Type $type,
        public readonly bool $required,
        public readonly string $description,
    ) {}
}
