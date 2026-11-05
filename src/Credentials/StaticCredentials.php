<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Credentials;

/**
 * Credentials fixed at construction, for scripts and tests.
 */
final class StaticCredentials implements CredentialProvider
{
    public function __construct(
        private readonly ?string $integrationKey = null,
        private readonly ?string $secretKey = null,
        private readonly ?string $publishableKey = null,
        private readonly ?string $shareLink = null,
        private readonly ?string $memberKey = null,
    ) {}

    public function secret(Credential $credential): ?string
    {
        return match ($credential) {
            Credential::IntegrationKey => $this->integrationKey,
            Credential::SecretKey => $this->secretKey,
            Credential::PublishableKey => $this->publishableKey,
            Credential::ShareLink => $this->shareLink,
            Credential::MemberKey => $this->memberKey,
        };
    }
}
