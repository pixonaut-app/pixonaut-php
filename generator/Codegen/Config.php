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
     */
    public function __construct(
        public readonly array $sensitive = [],
        public readonly array $generateSkipped = [],
        public readonly array $notSecret = [],
    ) {}

    public static function load(string $file): self
    {
        /** @var array{sensitive?: array<string, string>, generateSkipped?: array<string, string>, notSecret?: array<string, array<string, string>>} $config */
        $config = require $file;

        return new self($config['sensitive'] ?? [], $config['generateSkipped'] ?? [], $config['notSecret'] ?? []);
    }
}
