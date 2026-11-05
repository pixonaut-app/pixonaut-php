<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Support;

use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Http\Deprecation;
use Pixonaut\Sdk\Http\Listener;
use Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\Sdk\Http\Result;

final class RecordingListener extends Listener
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<Deprecation> */
    public array $deprecations = [];

    /** @var list<ApiException> */
    public array $unauthorized = [];

    /** @var list<array{PreparedRequest, ?Result, ?ApiException}> */
    public array $after = [];

    public function body(string $operation, array $body): array
    {
        $this->calls[] = 'body ' . $operation;
        $body['touched'] = true;

        return $body;
    }

    public function before(PreparedRequest $request): void
    {
        $this->calls[] = 'before ' . $request->operation;
    }

    public function after(PreparedRequest $request, ?Result $result, ?ApiException $error): void
    {
        $this->calls[] = 'after ' . $request->operation;
        $this->after[] = [$request, $result, $error];
    }

    public function unauthorized(string $operation, ApiException $error): void
    {
        $this->calls[] = 'unauthorized ' . $operation;
        $this->unauthorized[] = $error;
    }

    public function deprecated(string $operation, Deprecation $deprecation): void
    {
        $this->calls[] = 'deprecated ' . $operation;
        $this->deprecations[] = $deprecation;
    }
}
