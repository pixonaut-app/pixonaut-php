<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * Everything the generator prints, read from one spec.
 */
final class Api
{
    /**
     * @param array<string, ModelDef>    $models
     * @param array<string, UnionDef>    $unions
     * @param array<string, EnumDef>     $enums
     * @param list<OperationDef>         $operations
     * @param array<string, list<int>>   $errorCodes   Error code → the statuses it is documented with.
     * @param array<string, string>      $errorDescriptions Error code → the spec's description, where it has one.
     * @param list<int>                  $errorStatuses Every status the spec documents an unsuccessful answer with.
     */
    public function __construct(
        public readonly string $version,
        public readonly array $models,
        public readonly array $unions,
        public readonly array $enums,
        public readonly array $operations,
        public readonly array $errorCodes,
        public readonly array $errorDescriptions,
        public readonly array $errorStatuses,
    ) {}
}
