<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Credentials;

/**
 * The credentials Pixonaut accepts. Values are the spec's security scheme
 * names.
 */
enum Credential: string
{
    /** A connected store's integration key (`ik_`), kept on the server. */
    case IntegrationKey = 'integrationKey';

    /** A workspace secret key (`sk_`), kept on the server. */
    case SecretKey = 'secretKey';

    /**
     * A publishable key (`pk_`). It is bound to an origin, so it is sent with
     * the configured `Origin`.
     */
    case PublishableKey = 'publishableKey';

    /** A share link's token. */
    case ShareLink = 'shareLink';

    /** A member's personal key. */
    case MemberKey = 'memberKey';
}
