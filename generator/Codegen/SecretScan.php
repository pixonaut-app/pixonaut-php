<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * Finds answer fields named like a secret, so that an operation answering
 * one is either marked sensitive or the field is explained in
 * `generator/config.php`.
 */
final class SecretScan
{
    /**
     * `key`, `secret` or `token`, or a name ending in `Secret` or `Token`
     * (`proofToken`). Names ending in `Key` are left out: in this API they
     * are identifiers (`contentKey`, `fieldKey`), and the credentials it
     * answers are objects holding a `key`.
     */
    private const SECRET_NAME = '/^(?:key|secret|token)$|[a-z0-9](?:Secret|Token)$/';

    /**
     * Every secret-like answer field, by operation id, as JSON paths.
     *
     * @return array<string, list<string>>
     */
    public static function find(Api $api): array
    {
        $found = [];
        foreach ($api->operations as $operation) {
            $paths = [];
            foreach ($operation->responses as $type) {
                self::walk($api, $type, '$', $paths, []);
            }
            if ($paths !== []) {
                $paths = array_values(array_unique($paths));
                sort($paths);
                $found[$operation->id] = $paths;
            }
        }

        return $found;
    }

    /**
     * @param list<string>        $paths
     * @param array<string, true> $seen  Classes already on the way here.
     */
    private static function walk(Api $api, Type $type, string $path, array &$paths, array $seen): void
    {
        foreach ([$type->item, ...$type->items] as $inner) {
            if ($inner !== null) {
                self::walk($api, $inner, $path . '[]', $paths, $seen);
            }
        }
        foreach ($type->variants as $variant) {
            self::walk($api, $variant, $path, $paths, $seen);
        }
        if ($type->kind !== Type::MODEL || isset($seen[$type->class]) || !isset($api->models[$type->class])) {
            return;
        }
        $seen[$type->class] = true;
        foreach ($api->models[$type->class]->properties as $property) {
            $field = $path . '.' . $property->key;
            if (preg_match(self::SECRET_NAME, $property->key) === 1) {
                $paths[] = $field;
            }
            self::walk($api, $property->type, $field, $paths, $seen);
        }
    }
}
