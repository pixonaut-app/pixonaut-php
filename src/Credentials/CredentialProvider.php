<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Credentials;

/**
 * Where the client gets its credentials. It is asked at every send, so a host
 * can rotate or pause a key between two calls.
 */
interface CredentialProvider
{
    /**
     * The secret for a credential, or null (or an empty string) while the
     * host holds none.
     */
    public function secret(Credential $credential): ?string;
}
