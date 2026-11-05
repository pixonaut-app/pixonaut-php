<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Turns a spec into the generated tree, writes it, or tells how a written
 * tree differs from it.
 */
final class Generator
{
    public function __construct(
        private readonly string $namespace,
        private readonly Config $config,
    ) {}

    /**
     * Every generated file, by its path inside the tree, in path order.
     *
     * @return array<string, string>
     */
    public function files(string $specJson): array
    {
        $spec = json_decode($specJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($spec)) {
            throw new GeneratorException('The spec is not a JSON object.');
        }
        /** @var array<string, mixed> $spec */
        $api = (new Compiler($spec, $this->config))->compile();
        $models = new ModelPrinter($this->namespace);
        $support = new SupportPrinter($this->namespace);

        $files = [
            'ApiVersion.php' => $support->apiVersion($api, hash('sha256', $specJson)),
            'ErrorCode.php' => $support->errorCode($api),
            'Operations.php' => $support->operations($api),
        ];
        foreach ($api->models as $model) {
            $files['Model/' . $model->name . '.php'] = $models->model($model);
        }
        foreach ($api->unions as $union) {
            $files['Model/' . $union->name . '.php'] = $models->union($union);
        }
        foreach ($api->enums as $enum) {
            $files['Enum/' . $enum->name . '.php'] = $models->enum($enum);
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Writes the tree into `$directory`, removing every file it no longer
     * produces.
     *
     * @return array{int, int} Files written and files removed.
     */
    public function write(string $specJson, string $directory): array
    {
        $files = $this->files($specJson);
        $written = 0;
        foreach ($files as $path => $contents) {
            $target = $directory . '/' . $path;
            if (is_file($target) && file_get_contents($target) === $contents) {
                continue;
            }
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o777, true) && !is_dir(dirname($target))) {
                throw new GeneratorException('Cannot create ' . dirname($target) . '.');
            }
            if (file_put_contents($target, $contents) === false) {
                throw new GeneratorException('Cannot write ' . $target . '.');
            }
            ++$written;
        }
        $removed = 0;
        foreach (self::existing($directory) as $path) {
            if (!isset($files[$path])) {
                unlink($directory . '/' . $path);
                ++$removed;
            }
        }
        self::removeEmptyDirectories($directory);

        return [$written, $removed];
    }

    /**
     * Regenerates into a temporary directory and compares it with
     * `$directory`.
     *
     * @return list<string> Each differing file, with what is wrong with it.
     */
    public function check(string $specJson, string $directory): array
    {
        $temporary = sys_get_temp_dir() . '/pixonaut-generate-' . bin2hex(random_bytes(8));
        mkdir($temporary, 0o777, true);
        try {
            $this->write($specJson, $temporary);
            $expected = self::existing($temporary);
            $actual = self::existing($directory);
            $differences = [];
            foreach (array_diff($expected, $actual) as $path) {
                $differences[] = $path . ': missing';
            }
            foreach (array_diff($actual, $expected) as $path) {
                $differences[] = $path . ': not generated';
            }
            foreach (array_intersect($expected, $actual) as $path) {
                if (file_get_contents($temporary . '/' . $path) !== file_get_contents($directory . '/' . $path)) {
                    $differences[] = $path . ': differs';
                }
            }
            sort($differences);

            return $differences;
        } finally {
            self::removeTree($temporary);
        }
    }

    /**
     * @return list<string> Paths relative to `$directory`, sorted.
     */
    private static function existing(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $paths = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $paths[] = substr($file->getPathname(), strlen($directory) + 1);
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    private static function removeEmptyDirectories(string $directory): void
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
            if ($entry->isDir() && (new FilesystemIterator($entry->getPathname()))->valid() === false) {
                rmdir($entry->getPathname());
            }
        }
    }

    private static function removeTree(string $directory): void
    {
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
