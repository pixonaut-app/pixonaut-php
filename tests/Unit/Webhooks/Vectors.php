<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Webhooks;

/**
 * The pinned spec's signature vectors and webhooks, read at run time, so a
 * new pin runs the tests against its own files.
 */
final class Vectors
{
    private const VECTORS = __DIR__ . '/../../../spec/webhook-signature-vectors.json';

    private const SPEC = __DIR__ . '/../../../spec/openapi.json';

    /**
     * @return array{now: int, toleranceSeconds: int, scheme: string, valid: list<array<string, mixed>>, invalid: list<array<string, mixed>>}
     */
    public static function signatures(): array
    {
        /** @var array{now: int, toleranceSeconds: int, scheme: string, valid: list<array<string, mixed>>, invalid: list<array<string, mixed>>} */
        return self::read(self::VECTORS);
    }

    /**
     * Every webhook example in the spec, by its event type.
     *
     * @return array<string, array{string, array<string, mixed>}> Data set name → event type, example body.
     */
    public static function webhookExamples(): array
    {
        $examples = [];
        foreach (self::webhooks() as $type => $webhook) {
            /** @var array{requestBody?: array{content?: array{'application/json'?: array{examples?: array<string, array{value: array<string, mixed>}>}}}} $post */
            $post = $webhook['post'];
            $bodies = $post['requestBody']['content']['application/json']['examples'] ?? [];
            foreach ($bodies as $name => $example) {
                $examples[$type . ' ' . $name] = [$type, $example['value']];
            }
        }

        return $examples;
    }

    /**
     * @return array<string, array{post: array<string, mixed>}>
     */
    public static function webhooks(): array
    {
        /** @var array<string, array{post: array<string, mixed>}> */
        return self::read(self::SPEC)['webhooks'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $file): array
    {
        $json = file_get_contents($file);
        if ($json === false) {
            throw new \RuntimeException('Cannot read ' . $file . '.');
        }
        /** @var array<string, mixed> */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
