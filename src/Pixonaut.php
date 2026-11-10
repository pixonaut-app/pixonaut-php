<?php

declare(strict_types=1);

namespace Pixonaut\Sdk;

use InvalidArgumentException;
use JsonException;
use Pixonaut\Sdk\Credentials\Credential;
use Pixonaut\Sdk\Credentials\CredentialProvider;
use Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\Sdk\Generated\Resources;
use Pixonaut\Sdk\Http\Catalogue;
use Pixonaut\Sdk\Http\Deprecation;
use Pixonaut\Sdk\Http\ErrorMapper;
use Pixonaut\Sdk\Http\Listener;
use Pixonaut\Sdk\Http\NullListener;
use Pixonaut\Sdk\Http\Operation;
use Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\Sdk\Http\Result;
use Pixonaut\Sdk\Http\Uuid;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The Pixonaut API client. It holds no static state: two instances with
 * different keys or hosts never share anything.
 */
final class Pixonaut
{
    use Resources;

    public const VERSION = '3.0.0'; // x-release-please-version

    /**
     * Times a request answered `409 idempotency_key_in_use` is sent again
     * before the error goes to the caller.
     */
    public const MAX_IN_USE_RESENDS = 3;

    /** Wait when `idempotency_key_in_use` came without `Retry-After`. */
    private const DEFAULT_IN_USE_WAIT_SECONDS = 1;

    /**
     * Pixonaut asks for at most a couple of seconds; a longer wait would hold
     * the caller, so it is capped and the bounded resends do the rest.
     */
    private const MAX_IN_USE_WAIT_SECONDS = 5;

    /** What Pixonaut accepts as an `Idempotency-Key`: 1 to 255 printable ASCII characters. */
    private const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7e]{1,255}\z/';

    /** A header value: printable characters and spaces, no line break. */
    private const HEADER_VALUE_PATTERN = '/^[\x20-\x7e\x80-\xff]*\z/';

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const UNAUTHORIZED_STATUS = 401;

    /** The status Pixonaut answers `idempotency_key_in_use` with. */
    private const CONFLICT_STATUS = 409;

    private const SUCCESS_MIN = 200;

    private const SUCCESS_MAX = 299;

    private readonly LoggerInterface $logger;

    private readonly Listener $listener;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly CredentialProvider $credentials,
        private readonly Catalogue $catalogue,
        ?LoggerInterface $logger = null,
        ?Listener $listener = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->listener = $listener ?? new NullListener();
    }

    /**
     * Prepares and sends a request.
     *
     * @param array<string, string|int>                     $path           Values for the path template's `{name}`s.
     * @param array<string, scalar|list<scalar>|null>       $query          Query parameters; null ones are left out, a list is sent as the name repeated.
     * @param array<array-key, mixed>|null                  $body           JSON body, or null for none.
     * @param string|null                                   $idempotencyKey Key for an idempotent operation; a UUID when null.
     * @param array<string, string|null>                    $headers        Headers the operation declares, e.g. `Prefer`; null ones are left out.
     *
     * @throws ApiException             When Pixonaut refused the request or could not be reached.
     * @throws InvalidArgumentException When the request does not fit its operation; nothing is sent.
     */
    public function call(string $operation, array $path = [], array $query = [], ?array $body = null, ?string $idempotencyKey = null, array $headers = []): Result
    {
        return $this->send($this->prepare($operation, $path, $query, $body, $idempotencyKey, $headers));
    }

    /**
     * Builds a request once: target, encoded body and idempotency key. Keep
     * its `toArray()` to send the same request again later.
     *
     * @param array<string, string|int>               $path           Values for the path template's `{name}`s.
     * @param array<string, scalar|list<scalar>|null> $query          Query parameters; null ones are left out, a list is sent as the name repeated.
     * @param array<array-key, mixed>|null            $body           JSON body, or null for none.
     * @param string|null                             $idempotencyKey Key for an idempotent operation; a UUID when null.
     * @param array<string, string|null>              $headers        Headers the operation declares, e.g. `Prefer`; null ones are left out.
     *
     * @throws InvalidArgumentException When the operation is unknown, a path value is missing or empty, the body is not encodable, or the key or a header does not fit the operation.
     */
    public function prepare(string $operation, array $path = [], array $query = [], ?array $body = null, ?string $idempotencyKey = null, array $headers = []): PreparedRequest
    {
        $descriptor = $this->catalogue->operation($operation);

        $target = $this->target($descriptor, $path, $query);

        $encoded = null;
        if ($body !== null) {
            $body = $this->listener->body($operation, $body);
            try {
                $encoded = $body === [] ? '{}' : json_encode($body, self::JSON_FLAGS);
            } catch (JsonException $e) {
                throw new InvalidArgumentException(sprintf('%s: the body is not encodable as JSON: %s', $operation, $e->getMessage()), 0, $e);
            }
        }

        if ($descriptor->idempotent && $idempotencyKey === null) {
            $idempotencyKey = Uuid::v4();
        }

        $declared = [];
        foreach ($headers as $name => $value) {
            if ($value !== null) {
                $declared[strtolower($name)] = $value;
            }
        }

        $request = new PreparedRequest($operation, $target, $encoded, $idempotencyKey, $declared);
        $this->assertSendable($descriptor, $request);

        return $request;
    }

    /**
     * Sends a prepared request, again while Pixonaut is still answering an
     * earlier one with the same key (`409 idempotency_key_in_use`), at most
     * `MAX_IN_USE_RESENDS` times. Nothing else is retried.
     *
     * @throws ApiException             When Pixonaut refused the request or could not be reached.
     * @throws InvalidArgumentException When the request does not fit its operation; nothing is sent.
     */
    public function send(PreparedRequest $request): Result
    {
        $operation = $this->catalogue->operation($request->operation);
        $this->assertSendable($operation, $request);

        for ($resends = 0; ; ++$resends) {
            $psr = $this->build($operation, $request);
            $this->listener->before($request);
            try {
                $response = $this->client->sendRequest($psr);
            } catch (ClientExceptionInterface $failure) {
                $error = ErrorMapper::transportFailed($operation, $failure);
                // The PSR-18 exception may carry the request, credential included.
                $this->listener->after($request, null, ErrorMapper::withoutPrevious($error));
                throw $this->raise($error);
            }

            $outcome = $this->answer($operation, $request, $response);
            if ($outcome instanceof Result) {
                return $outcome;
            }
            if (
                $outcome->status === self::CONFLICT_STATUS
                && $outcome->errorCode === ApiException::IDEMPOTENCY_KEY_IN_USE
                && $request->idempotencyKey !== null
                && $resends < self::MAX_IN_USE_RESENDS
            ) {
                $this->config->sleep(min($outcome->retryAfter ?? self::DEFAULT_IN_USE_WAIT_SECONDS, self::MAX_IN_USE_WAIT_SECONDS));
                continue;
            }
            throw $this->raise($outcome);
        }
    }

    /**
     * The PSR-7 request for a prepared request, for a host that sends several
     * at once with its own HTTP stack; pass each response to `handle()`.
     * Calls the listener's `before()`.
     *
     * @throws ApiException             `not_connected` when the operation needs a credential the host does not hold.
     * @throws InvalidArgumentException When the request does not fit its operation.
     */
    public function toPsrRequest(PreparedRequest $request): RequestInterface
    {
        $operation = $this->catalogue->operation($request->operation);
        $this->assertSendable($operation, $request);
        $psr = $this->build($operation, $request);
        $this->listener->before($request);

        return $psr;
    }

    /**
     * Reads the response to a request built with `toPsrRequest()`, with the
     * same error mapping as `send()`. Calls the listener's `after()`.
     *
     * @throws ApiException When the response is not a success.
     */
    public function handle(PreparedRequest $request, ResponseInterface $response): Result
    {
        $outcome = $this->answer($this->catalogue->operation($request->operation), $request, $response);
        if ($outcome instanceof ApiException) {
            throw $this->raise($outcome);
        }

        return $outcome;
    }

    /**
     * @param array<string, string|int>               $path
     * @param array<string, scalar|list<scalar>|null> $query
     */
    private function target(Operation $operation, array $path, array $query): string
    {
        $used = [];
        $target = preg_replace_callback(
            '/\{([^}]+)\}/',
            static function (array $found) use ($path, $operation, &$used): string {
                $name = $found[1];
                $value = (string) ($path[$name] ?? '');
                if ($value === '') {
                    throw new InvalidArgumentException(sprintf('%s needs the path value "%s".', $operation->id, $name));
                }
                // A dot segment would point the request at another path once resolved.
                if ($value === '.' || $value === '..') {
                    throw new InvalidArgumentException(sprintf('%s cannot take "%s" as the path value "%s".', $operation->id, $value, $name));
                }
                $used[$name] = true;

                return rawurlencode($value);
            },
            $operation->path,
        );
        foreach (array_keys($path) as $name) {
            if (!isset($used[$name])) {
                throw new InvalidArgumentException(sprintf('%s has no path value "%s".', $operation->id, $name));
            }
        }

        $pairs = [];
        foreach ($query as $name => $value) {
            // A list repeats the name, the way the API reads several values.
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item !== null) {
                    $pairs[] = rawurlencode($name) . '=' . rawurlencode(is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
                }
            }
        }
        if ($pairs !== []) {
            $target .= '?' . implode('&', $pairs);
        }

        return (string) $target;
    }

    /**
     * A request built by hand or restored from a damaged snapshot must not
     * reach Pixonaut: an idempotent write without its key would run twice.
     *
     * @throws InvalidArgumentException When the request does not fit its operation.
     */
    private function assertSendable(Operation $operation, PreparedRequest $request): void
    {
        if (!$operation->matches($request->target)) {
            throw new InvalidArgumentException(sprintf('%s cannot be sent to %s.', $request->operation, $request->target));
        }
        foreach ($request->headers as $name => $value) {
            // Only what the operation declares: never a credential or a header the client sets itself.
            if (!$operation->declares($name)) {
                throw new InvalidArgumentException(sprintf('%s takes no %s header.', $request->operation, $name));
            }
            if (preg_match(self::HEADER_VALUE_PATTERN, $value) !== 1) {
                throw new InvalidArgumentException(sprintf('%s: the %s header holds a character a header cannot.', $request->operation, $name));
            }
        }
        if (!$operation->idempotent) {
            if ($request->idempotencyKey !== null) {
                throw new InvalidArgumentException(sprintf('%s takes no Idempotency-Key.', $request->operation));
            }

            return;
        }
        if ($request->idempotencyKey === null || preg_match(self::IDEMPOTENCY_KEY_PATTERN, $request->idempotencyKey) !== 1) {
            throw new InvalidArgumentException(sprintf('%s needs an Idempotency-Key of 1 to 255 printable ASCII characters.', $request->operation));
        }
    }

    /**
     * @throws ApiException `not_connected` when the operation needs a credential the host does not hold.
     */
    private function build(Operation $operation, PreparedRequest $request): RequestInterface
    {
        $psr = $this->requestFactory->createRequest($operation->method, $this->config->baseUrl . $request->target)
            ->withHeader('Accept', $operation->accept)
            ->withHeader('User-Agent', $this->config->userAgent());
        if ($request->body !== null) {
            $psr = $psr->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($request->body));
        }
        if ($request->idempotencyKey !== null) {
            $psr = $psr->withHeader('Idempotency-Key', $request->idempotencyKey);
        }
        foreach ($request->headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }

        foreach ($this->credentialOrder($operation) as $credential) {
            $secret = $this->credentials->secret($credential);
            if ($secret === null || $secret === '') {
                continue;
            }
            $psr = $psr->withHeader('Authorization', 'Bearer ' . $secret);
            // A publishable key is refused from any origin it is not bound to.
            if ($credential === Credential::PublishableKey && $this->config->origin !== null) {
                $psr = $psr->withHeader('Origin', $this->config->origin);
            }

            return $psr;
        }

        if (!$operation->anonymous) {
            throw new ApiException($operation->id, 0, ApiException::NOT_CONNECTED, false);
        }

        return $psr;
    }

    /**
     * The credentials the operation accepts, in the configured preference;
     * one the preference leaves out comes last, in the operation's order.
     *
     * @return list<Credential>
     */
    private function credentialOrder(Operation $operation): array
    {
        $order = array_values(array_filter(
            $this->config->credentialPreference,
            static fn(Credential $credential): bool => $operation->accepts($credential),
        ));
        foreach ($operation->credentials as $credential) {
            if (!in_array($credential, $order, true)) {
                $order[] = $credential;
            }
        }

        return $order;
    }

    /**
     * The result of a response, or the error it carries, the deprecation
     * noted and the listener's `after()` called.
     */
    private function answer(Operation $operation, PreparedRequest $request, ResponseInterface $response): Result|ApiException
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }
        $status = $response->getStatusCode();
        $deprecation = $this->deprecation($operation, $headers);
        $success = $status >= self::SUCCESS_MIN && $status <= self::SUCCESS_MAX;

        if ($success && $operation->accept === Operation::EVENT_STREAM && self::isEventStream($headers['content-type'] ?? '')) {
            // Left unread: the caller reads the events as they arrive.
            $result = new Result($operation->id, $status, $headers, '', $deprecation, $operation->sensitive, $response->getBody());
            $this->listener->after($request, $result, null);

            return $result;
        }
        try {
            $body = (string) $response->getBody();
        } catch (RuntimeException $failure) {
            // A client that streams the body, as Symfony's does, reports a
            // connection cut mid-answer here rather than from sendRequest().
            $error = ErrorMapper::transportFailed($operation, $failure);
            $this->listener->after($request, null, ErrorMapper::withoutPrevious($error));

            return $error;
        }
        if ($success) {
            // A success that is not what the operation answers, e.g. a proxy's page.
            if (($operation->accept === Operation::JSON && $body !== '' && !self::isJson($body)) || $operation->accept === Operation::EVENT_STREAM) {
                $error = (new Result($operation->id, $status, $headers, $body, $deprecation, $operation->sensitive))->unexpected();
                $this->listener->after($request, null, $error);

                return $error;
            }
            $result = new Result($operation->id, $status, $headers, $body, $deprecation, $operation->sensitive);
            $this->listener->after($request, $result, null);

            return $result;
        }

        $error = ErrorMapper::fromResponse($operation, $status, $headers, $body, $this->config->now());
        $this->listener->after($request, null, $error);

        return $error;
    }

    /**
     * Whether a `Content-Type` is `text/event-stream`, parameters and case aside.
     */
    private static function isEventStream(string $contentType): bool
    {
        return strtolower(trim(explode(';', $contentType)[0])) === Operation::EVENT_STREAM;
    }

    private static function isJson(string $body): bool
    {
        json_decode($body);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @param array<string, string> $headers Lower-case names.
     */
    private function deprecation(Operation $operation, array $headers): ?Deprecation
    {
        if (!isset($headers['deprecation'])) {
            return null;
        }
        $deprecation = Deprecation::fromHeaders($operation->id, $headers['deprecation'], $headers['sunset'] ?? null);
        $this->logger->warning(
            sprintf(
                'Pixonaut deprecated something %s uses (Deprecation: %s, Sunset: %s). Update before the sunset.',
                $operation->id,
                $deprecation->header,
                $deprecation->sunsetHeader ?? 'none',
            ),
            [
                'operation' => $operation->id,
                'deprecation' => $deprecation->header,
                'sunset' => $deprecation->sunsetHeader,
            ],
        );
        $this->listener->deprecated($operation->id, $deprecation);

        return $deprecation;
    }

    /**
     * Logs an error the spec does not document, and tells the listener when
     * the credential was refused.
     */
    private function raise(ApiException $error): ApiException
    {
        $knownCode = in_array($error->errorCode, ApiException::CLIENT_CODES, true)
            || $this->catalogue->isKnownErrorCode($error->errorCode);
        $knownStatus = $error->status === 0 || $this->catalogue->isKnownStatus($error->status);
        if (!$knownCode || !$knownStatus) {
            $this->logger->error($error->getMessage(), [
                'operation' => $error->operation,
                'status' => $error->status,
                'code' => $error->errorCode,
                'requestId' => $error->requestId,
                'details' => $error->details,
            ]);
        }

        if ($error->errorCode === ApiException::UNAUTHORIZED || $error->status === self::UNAUTHORIZED_STATUS) {
            $this->listener->unauthorized($error->operation, $error);
        }

        return $error;
    }
}
