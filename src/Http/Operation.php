<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

use InvalidArgumentException;
use Pixonaut\Sdk\Credentials\Credential;

/**
 * One API operation, as the client needs to know it to send a request.
 */
final class Operation
{
    public const JSON = 'application/json';

    /**
     * @param string           $id          The spec's `operationId`, e.g. `designs.commit`.
     * @param string           $method      HTTP method, upper case.
     * @param string           $path        Path template as the spec writes it, e.g. `/v1/designs/{id}`.
     * @param list<Credential> $credentials Credentials the operation accepts, in the spec's order.
     * @param bool             $anonymous   Whether it may be called without a credential.
     * @param bool             $idempotent  Whether it takes an `Idempotency-Key`.
     * @param string           $accept      The `Accept` header: what a successful answer is.
     * @param bool             $sensitive   Whether its answers carry secrets, so an error keeps none of the body.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $method,
        public readonly string $path,
        public readonly array $credentials = [],
        public readonly bool $anonymous = false,
        public readonly bool $idempotent = false,
        public readonly string $accept = self::JSON,
        public readonly bool $sensitive = false,
    ) {
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException(sprintf('%s: the path template must start with "/".', $id));
        }
    }

    /**
     * Whether a request target (path and query) is one of this operation's
     * paths: each `{name}` stands for one non-empty path segment that is
     * not a dot segment, and nothing in it is a control character or a space.
     */
    public function matches(string $target): bool
    {
        $segments = preg_split('/(\{[^}]+\})/', $this->path, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pattern = '';
        foreach ($segments === false ? [] : $segments as $segment) {
            $pattern .= str_starts_with($segment, '{') ? '([^/?#\x00-\x20\x7f]+)' : preg_quote($segment, '~');
        }
        if (preg_match('~^' . $pattern . '(?:\?[^#\x00-\x20\x7f]*)?\z~', $target, $match) !== 1) {
            return false;
        }
        array_shift($match);
        foreach ($match as $value) {
            // A dot segment, encoded or not, points the request at another
            // path once an HTTP stack resolves it.
            if (in_array(rawurldecode($value), ['.', '..'], true)) {
                return false;
            }
        }

        return true;
    }

    public function accepts(Credential $credential): bool
    {
        return in_array($credential, $this->credentials, true);
    }
}
