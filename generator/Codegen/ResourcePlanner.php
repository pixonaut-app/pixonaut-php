<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * Places each operation on a resource and names its method, from the tags
 * and `generator/config.php`.
 */
final class ResourcePlanner
{
    /** The verb whose noun stays plural: `designs.revisions.list` → `listRevisions`. */
    private const LIST_VERB = 'list';

    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * @param list<OperationDef> $operations
     *
     * @return list<ResourceDef> Sorted by name.
     */
    public function plan(array $operations): array
    {
        $ids = array_map(static fn(OperationDef $operation): string => $operation->id, $operations);
        foreach (array_keys($this->config->operationResources + $this->config->methodNames) as $id) {
            if (!in_array($id, $ids, true)) {
                throw new GeneratorException(sprintf('generator/config.php names %s, which the spec does not have.', $id));
            }
        }

        /** @var array<string, array<string, OperationDef>> $resources */
        $resources = [];
        foreach ($operations as $operation) {
            $resource = $this->resourceOf($operation);
            if ($resource === null) {
                continue;
            }
            $method = $this->methodName($operation, $resource);
            if (isset($resources[$resource][$method])) {
                throw new GeneratorException(sprintf(
                    '%s and %s would both be %s()->%s(); name one in generator/config.php under methodNames.',
                    $resources[$resource][$method]->id,
                    $operation->id,
                    $resource,
                    $method,
                ));
            }
            $resources[$resource][$method] = $operation;
        }
        foreach (array_keys($this->config->resourcePrefixes) as $resource) {
            if (!isset($resources[$resource])) {
                throw new GeneratorException(sprintf('generator/config.php gives a prefix to %s, which has no operations.', $resource));
            }
        }
        ksort($resources);

        $plan = [];
        foreach ($resources as $name => $methods) {
            $plan[] = new ResourceDef($name, Naming::className(Naming::pascal($name)), $methods);
        }

        return $plan;
    }

    private function resourceOf(OperationDef $operation): ?string
    {
        if (array_key_exists($operation->id, $this->config->operationResources)) {
            return $this->config->operationResources[$operation->id];
        }
        if (!array_key_exists($operation->tag, $this->config->resources)) {
            throw new GeneratorException(sprintf(
                '%s is tagged "%s", which generator/config.php does not map to a resource.',
                $operation->id,
                $operation->tag,
            ));
        }

        return $this->config->resources[$operation->tag];
    }

    /**
     * `designs.revisions.get` → `getRevision`, `designs.revisions.list` →
     * `listRevisions`, `designs.commit` → `commit`: the verb, then the nouns
     * after the resource's prefix, singular unless the verb lists them.
     */
    private function methodName(OperationDef $operation, string $resource): string
    {
        if (isset($this->config->methodNames[$operation->id])) {
            return $this->config->methodNames[$operation->id];
        }
        $prefix = ($this->config->resourcePrefixes[$resource] ?? $resource) . '.';
        $rest = str_starts_with($operation->id, $prefix) ? substr($operation->id, strlen($prefix)) : $operation->id;
        $segments = explode('.', $rest);
        $verb = (string) array_pop($segments);
        $nouns = '';
        foreach ($segments as $index => $noun) {
            $keepPlural = $verb === self::LIST_VERB && $index === array_key_last($segments);
            $nouns .= ucfirst($keepPlural ? $noun : self::singular($noun));
        }

        return $verb . $nouns;
    }

    private static function singular(string $noun): string
    {
        if (str_ends_with($noun, 'ies')) {
            return substr($noun, 0, -strlen('ies')) . 'y';
        }
        if (str_ends_with($noun, 's') && !str_ends_with($noun, 'ss')) {
            return substr($noun, 0, -1);
        }

        return $noun;
    }
}
