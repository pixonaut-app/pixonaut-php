<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Model;

/**
 * A generated model of a JSON object the API sends or accepts.
 */
interface Model
{
    /**
     * The object as JSON-ready values: what a request sends.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * The object as it was decoded, including fields this release does not
     * know; for a model built in code, the same as `toArray()`.
     *
     * @return array<string, mixed>
     */
    public function raw(): array;
}
