<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * One operation, as the client's `Operation` descriptor states it.
 */
final class OperationDef
{
    /**
     * @param list<string> $credentials Security scheme names, in the spec's order.
     * @param array<int, Type> $responses JSON bodies of successful answers, by status.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $method,
        public readonly string $path,
        public readonly array $credentials,
        public readonly bool $anonymous,
        public readonly bool $idempotent,
        public readonly string $accept,
        public readonly bool $sensitive,
        public readonly string $summary,
        public readonly ?Type $request,
        public readonly array $responses,
    ) {}
}
