<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

use InvalidArgumentException;

/**
 * What the client knows about the API: its operations, and the error codes
 * and statuses the spec documents. An answer outside them is still passed on,
 * and logged so it gets noticed.
 */
interface Catalogue
{
    /**
     * @throws InvalidArgumentException When the catalogue has no such operation.
     */
    public function operation(string $id): Operation;

    public function isKnownErrorCode(string $code): bool;

    public function isKnownStatus(int $status): bool;
}
