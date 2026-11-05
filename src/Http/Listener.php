<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

use Pixonaut\Sdk\Exception\ApiException;

/**
 * Hooks into the client's requests. Every method does nothing by default, so
 * a host overrides only the ones it needs. None of them ever sees a
 * credential.
 */
abstract class Listener
{
    /**
     * Changes a request body before it is encoded. Called once, when the
     * request is prepared; a request sent again from its snapshot is not
     * passed through it again.
     *
     * @param array<array-key, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    public function body(string $operation, array $body): array
    {
        return $body;
    }

    /**
     * Called before every send, including each resend.
     */
    public function before(PreparedRequest $request): void {}

    /**
     * Called after every send, including each resend, with the result or the
     * error it ended in.
     */
    public function after(PreparedRequest $request, ?Result $result, ?ApiException $error): void {}

    /**
     * Called when Pixonaut refused the credential: a `401` or `unauthorized`.
     */
    public function unauthorized(string $operation, ApiException $error): void {}

    /**
     * Called for each response that carries a deprecation notice. Keeping
     * and de-duplicating notices is the host's job.
     */
    public function deprecated(string $operation, Deprecation $deprecation): void {}
}
