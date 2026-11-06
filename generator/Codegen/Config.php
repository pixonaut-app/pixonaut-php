<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * `generator/config.php`: what the spec does not say, each with its reason.
 */
final class Config
{
    /**
     * @param array<string, string>                $sensitive          Operation id → why its answers carry secrets.
     * @param array<string, string>                $generateSkipped    `x-sdk-skip` operation id → why the SDK still calls it.
     * @param array<string, array<string, string>> $notSecret          Operation id → field path → why a field named like a
     *                                                                 secret is not one.
     * @param array<string, string|null>           $resources          Tag → the resource its operations are on, or null for none.
     * @param array<string, string|null>           $operationResources Operation id → its resource, where it is not its tag's.
     * @param array<string, string>                $resourcePrefixes   Resource → the `operationId` prefix its method names leave out.
     * @param array<string, string>                $methodNames        Operation id → its method name, where the derived one reads badly.
     */
    public function __construct(
        public readonly array $sensitive = [],
        public readonly array $generateSkipped = [],
        public readonly array $notSecret = [],
        public readonly array $resources = [],
        public readonly array $operationResources = [],
        public readonly array $resourcePrefixes = [],
        public readonly array $methodNames = [],
    ) {}

    public static function load(string $file): self
    {
        /** @var array{sensitive?: array<string, string>, generateSkipped?: array<string, string>, notSecret?: array<string, array<string, string>>, resources?: array<string, string|null>, operationResources?: array<string, string|null>, resourcePrefixes?: array<string, string>, methodNames?: array<string, string>} $config */
        $config = require $file;

        return new self(
            $config['sensitive'] ?? [],
            $config['generateSkipped'] ?? [],
            $config['notSecret'] ?? [],
            $config['resources'] ?? [],
            $config['operationResources'] ?? [],
            $config['resourcePrefixes'] ?? [],
            $config['methodNames'] ?? [],
        );
    }
}
