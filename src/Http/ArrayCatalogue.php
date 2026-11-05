<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

use InvalidArgumentException;

/**
 * A catalogue built from lists.
 */
final class ArrayCatalogue implements Catalogue
{
    /** @var array<string, Operation> */
    private readonly array $operations;

    /** @var array<string, true> */
    private readonly array $errorCodes;

    /** @var array<int, true> */
    private readonly array $statuses;

    /**
     * @param list<Operation> $operations
     * @param list<string>    $errorCodes Error codes the spec documents.
     * @param list<int>       $statuses   Error statuses the spec documents.
     */
    public function __construct(array $operations, array $errorCodes = [], array $statuses = [])
    {
        $byId = [];
        foreach ($operations as $operation) {
            if (isset($byId[$operation->id])) {
                throw new InvalidArgumentException(sprintf('Operation %s is listed twice.', $operation->id));
            }
            $byId[$operation->id] = $operation;
        }
        $this->operations = $byId;
        $this->errorCodes = array_fill_keys($errorCodes, true);
        $this->statuses = array_fill_keys($statuses, true);
    }

    public function operation(string $id): Operation
    {
        return $this->operations[$id] ?? throw new InvalidArgumentException(sprintf('Unknown Pixonaut operation %s.', $id));
    }

    public function isKnownErrorCode(string $code): bool
    {
        return isset($this->errorCodes[$code]);
    }

    public function isKnownStatus(int $status): bool
    {
        return isset($this->statuses[$status]);
    }
}
