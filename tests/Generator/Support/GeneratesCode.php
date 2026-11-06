<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator\Support;

use FilesystemIterator;
use Pixonaut\Sdk\Generator\Codegen\Config;
use Pixonaut\Sdk\Generator\Codegen\Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Generates a small fixture spec into a temporary directory under a
 * namespace of its own, and loads the result.
 */
trait GeneratesCode
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            self::removeTree($directory);
        }
        $this->directories = [];
    }

    /**
     * @param array<string, mixed> $schemas Component schemas.
     * @param array<string, mixed> $paths
     * @param array<string, mixed> $extra   Further top-level fields.
     *
     * @return array<string, mixed>
     */
    protected static function spec(array $schemas = [], array $paths = [], array $extra = []): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Fixture', 'version' => '9.9.9'],
            'paths' => $paths === [] ? new \stdClass() : $paths,
            'components' => ['schemas' => $schemas === [] ? new \stdClass() : $schemas],
        ] + $extra;
    }

    /**
     * Generates `$spec` and loads every class, returning the namespace.
     *
     * @param array<string, mixed> $spec
     */
    protected function load(array $spec, ?Config $config = null): string
    {
        $namespace = 'Pixonaut\\Sdk\\Tests\\Generator\\Out\\Run' . bin2hex(random_bytes(8));
        $directory = $this->directory();
        (new Generator($namespace, $config ?? new Config()))->write(self::json($spec), $directory);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var SplFileInfo $file */
            require_once $file->getPathname();
        }

        return $namespace;
    }

    /**
     * @param array<string, mixed> $spec
     */
    protected static function json(array $spec): string
    {
        return json_encode($spec, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    protected function directory(): string
    {
        $directory = sys_get_temp_dir() . '/pixonaut-generator-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0o777, true);
        $this->directories[] = $directory;

        return $directory;
    }

    /**
     * A one-operation path item.
     *
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    protected static function operation(string $id, array $operation = [], string $method = 'post'): array
    {
        return [$method => $operation + ['operationId' => $id, 'responses' => ['204' => ['description' => 'Done']]]];
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<int|string, mixed>
     */
    protected static function json200(array $schema): array
    {
        return ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $schema]]]];
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
