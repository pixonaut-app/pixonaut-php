<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * One resource class, reached from the client as `$pixonaut-><name>()`.
 */
final class ResourceDef
{
    /**
     * @param string                       $name    The accessor, e.g. `designs`.
     * @param string                       $class   The class, without namespace, e.g. `Designs`.
     * @param array<string, OperationDef>  $methods Method name → the operation it sends, in the spec's order.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $class,
        public readonly array $methods,
    ) {}
}
