<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Exception;

use UnexpectedValueException;

/**
 * A JSON document that does not have the shape its model describes: a
 * required field is missing, or a field has the wrong type.
 */
final class UnexpectedResponse extends UnexpectedValueException
{
    /**
     * @param string $jsonPath Where in the document, e.g. `$.items[2].id`.
     * @param string $problem  What is wrong there, e.g. `expected string, got int`.
     */
    public function __construct(
        public readonly string $jsonPath,
        public readonly string $problem,
    ) {
        parent::__construct($jsonPath . ': ' . $problem);
    }
}
