<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Fake;

use JsonException;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use stdClass;

/**
 * The Pixonaut API in memory, as the pinned spec describes it, behind
 * PSR-18 so the SDK reaches it without a socket.
 *
 * It routes each request by method and path template, checks its path
 * values, query, headers, body and credential against the operation, and
 * answers with the spec's example for the operation, or with what a test
 * set. Every answer it gives is checked against the spec's schema for its
 * status too, so the fake cannot drift from the spec unnoticed. What a
 * request breaks is kept in `violations()`, which a test asserts is empty.
 */
final class FakePixonaut implements ClientInterface
{
    /** Security scheme each credential prefix stands for, as Pixonaut issues them. */
    public const CREDENTIAL_PREFIXES = [
        'ik_' => 'integrationKey',
        'sk_' => 'secretKey',
        'pk_' => 'publishableKey',
        'mk_' => 'memberKey',
        'link_' => 'shareLink',
    ];

    /** Bytes of a binary answer: a PNG signature, then text naming what it stands for. */
    public const BINARY_PREFIX = "\x89PNG\r\n\x1a\n\x00";

    private const ANY_OPERATION = '*';

    private const KEY_IN_USE = 'idempotency_key_in_use';

    private const KEY_REUSED = 'idempotency_key_reused';

    private const BAD_REQUEST = 400;

    private const UNAUTHORIZED = 401;

    private const FORBIDDEN = 403;

    private const NOT_FOUND = 404;

    private const METHOD_NOT_ALLOWED = 405;

    private const CONFLICT = 409;

    private const UNPROCESSABLE = 422;

    private const OK = 200;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private readonly Spec $spec;

    public function __construct(private FakeState $state = new FakeState())
    {
        $this->spec = Spec::pinned();
    }

    public function state(): FakeState
    {
        return $this->state;
    }

    /**
     * Answers the next request to the operation with this, once.
     *
     * @param mixed                 $json    Encoded as the body; null for none.
     * @param array<string, string> $headers
     */
    public function answer(string $operation, int $status, mixed $json = null, array $headers = []): void
    {
        $this->spec->operation($operation);
        $body = $json === null ? '' : json_encode($json, self::JSON_FLAGS);
        if ($body !== '') {
            $headers += ['Content-Type' => SpecOperation::JSON];
        }
        $this->state->answers[$operation][] = ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    /**
     * Answers the next request to the operation with the spec's example for
     * the status, once: the example named, or the first.
     */
    public function answerExample(string $operation, int $status, ?string $example = null): void
    {
        $spec = $this->spec->operation($operation);
        $types = $spec->mediaTypes($status);
        if ($types === [] || in_array(SpecOperation::JSON, $types, true)) {
            $this->answer($operation, $status, $types === [] ? null : $spec->answerExample($status, $example));

            return;
        }
        $this->state->answers[$operation][] = ['status' => $status, 'body' => self::binary($operation, $types[0]), 'headers' => ['Content-Type' => $types[0]]];
    }

    /**
     * Makes the next request to the operation, or to any when null, go
     * wrong this way, once.
     */
    public function fault(Fault $fault, ?string $operation = null): void
    {
        if ($operation !== null) {
            $this->spec->operation($operation);
        }
        $this->state->faults[$operation ?? self::ANY_OPERATION][] = $fault;
    }

    /**
     * Tells the next `$times` requests with this key that one with it is
     * still being answered.
     */
    public function keyInFlight(string $key, int $times = 1, int $retryAfter = 1): void
    {
        $this->state->inFlight[$key] = ['times' => $times, 'retryAfter' => $retryAfter];
    }

    /**
     * Takes a credential as this security scheme, whatever its prefix.
     */
    public function credential(string $secret, string $scheme): void
    {
        $this->state->credentials[$secret] = $scheme;
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        return $this->state->violations;
    }

    /**
     * @return list<array{operation: string, method: string, target: string, status: int, replayed: bool}>
     */
    public function log(): array
    {
        return $this->state->log;
    }

    /**
     * How many requests to the operation were answered afresh, not replayed.
     */
    public function made(string $operation): int
    {
        return count(array_filter(
            $this->state->log,
            static fn(array $entry): bool => $entry['operation'] === $operation && !$entry['replayed'] && $entry['status'] >= self::OK && $entry['status'] < 300,
        ));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $requestId = 'req_fake_' . ++$this->state->requests;
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        $target = $path . ($request->getUri()->getQuery() === '' ? '' : '?' . $request->getUri()->getQuery());

        $route = $this->spec->route($method, $path);
        if ($route === null) {
            $known = $this->spec->hasPath($path);
            $this->violation(sprintf('%s %s: %s', $method, $path, $known ? 'the path takes no ' . $method : 'no operation has this path'));

            return $this->error($known ? self::METHOD_NOT_ALLOWED : self::NOT_FOUND, $known ? 'method_not_allowed' : 'not_found', $requestId);
        }
        [$operation, $values] = $route;
        $fault = $this->takeFault($operation->id);
        if ($fault?->kind === Fault::TRANSPORT) {
            $this->record($operation, $method, $target, 0, false);

            throw new FakeNetworkException($request, 'The fake dropped the connection to ' . $operation->id . '.');
        }

        $problems = $this->check($operation, $values, $request);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->violation($operation->id . ' ' . $problem);
            }

            return $this->respond($operation, $method, $target, false, $this->error(self::BAD_REQUEST, 'invalid_request', $requestId, ['problems' => $problems]));
        }

        $secret = self::bearer($request);
        $refused = $this->authorise($operation, $secret);
        if ($refused !== null) {
            $this->violation($operation->id . ' ' . $refused[1]);

            return $this->respond($operation, $method, $target, false, $this->error($refused[0], $refused[0] === self::UNAUTHORIZED ? 'unauthorized' : 'forbidden', $requestId));
        }

        $key = $operation->idempotent() ? $request->getHeaderLine('Idempotency-Key') : '';
        $replayKey = $secret . "\n" . $key;
        $fingerprint = hash('sha256', $method . ' ' . $target . "\n" . $request->getBody());
        if ($key !== '') {
            if (($this->state->inFlight[$key]['times'] ?? 0) > 0) {
                $retryAfter = $this->state->inFlight[$key]['retryAfter'];
                --$this->state->inFlight[$key]['times'];

                return $this->respond($operation, $method, $target, false, $this->documented($operation, self::CONFLICT, self::KEY_IN_USE, $requestId, ['Retry-After' => (string) $retryAfter]));
            }
            $first = $this->state->replays[$replayKey] ?? null;
            if ($first !== null) {
                if ($first['fingerprint'] !== $fingerprint) {
                    return $this->respond($operation, $method, $target, false, $this->documented($operation, self::UNPROCESSABLE, self::KEY_REUSED, $requestId));
                }

                return $this->respond($operation, $method, $target, true, new Response(
                    $first['status'],
                    ['Idempotent-Replayed' => 'true', 'X-Request-Id' => $requestId] + $first['headers'],
                    $first['body'],
                ));
            }
        }

        $answer = $this->answerFor($operation, $fault, $requestId);
        $status = $answer->getStatusCode();
        if ($key !== '' && $status >= self::OK && $status < 300) {
            $headers = [];
            foreach ($answer->getHeaders() as $name => $lines) {
                if (strtolower((string) $name) !== 'x-request-id') {
                    $headers[(string) $name] = implode(', ', $lines);
                }
            }
            $this->state->replays[$replayKey] = ['fingerprint' => $fingerprint, 'status' => $status, 'body' => (string) $answer->getBody(), 'headers' => $headers];
        }
        $response = $this->respond($operation, $method, $target, false, $answer);
        if ($fault?->kind === Fault::LOST_ANSWER) {
            throw new FakeNetworkException($request, 'The fake answered ' . $operation->id . ' and lost the answer.');
        }

        return $response;
    }

    /**
     * What the request breaks of the operation's path values, query,
     * headers and body.
     *
     * @param array<string, string> $values
     *
     * @return list<string>
     */
    private function check(SpecOperation $operation, array $values, RequestInterface $request): array
    {
        $problems = [];
        foreach ($operation->parametersIn('path') as $parameter) {
            $problems = [...$problems, ...$this->checkValue($operation, $parameter, [$values[$parameter->name] ?? ''])];
        }

        $query = self::query($request->getUri()->getQuery());
        foreach ($query as $name => $items) {
            $parameter = $operation->parameter('query', $name);
            if ($parameter === null) {
                $problems[] = sprintf('query %s: the operation has no such query parameter', $name);
                continue;
            }
            $problems = [...$problems, ...$this->checkValue($operation, $parameter, $items)];
        }
        foreach ($operation->parametersIn('query') as $parameter) {
            if ($parameter->required && !isset($query[$parameter->name])) {
                $problems[] = sprintf('query %s: required, and not sent', $parameter->name);
            }
        }

        foreach ($operation->parametersIn('header') as $parameter) {
            if (!$request->hasHeader($parameter->name)) {
                if ($parameter->required) {
                    $problems[] = sprintf('header %s: required, and not sent', $parameter->name);
                }
                continue;
            }
            $problems = [...$problems, ...$this->checkValue($operation, $parameter, [$request->getHeaderLine($parameter->name)], false)];
        }
        if (!$operation->idempotent() && $request->hasHeader('Idempotency-Key')) {
            $problems[] = 'header Idempotency-Key: the operation takes none';
        }

        return [...$problems, ...$this->checkBody($operation, $request)];
    }

    /**
     * @param list<string> $items The values sent, more than one when a query name repeats.
     *
     * @return list<string>
     */
    private function checkValue(SpecOperation $operation, SpecParameter $parameter, array $items, bool $listable = true): array
    {
        $first = null;
        foreach (self::candidates($items, $listable) as $candidate) {
            $problems = $operation->validateParameter($parameter, $candidate);
            if ($problems === []) {
                return [];
            }
            $first ??= $problems;
        }

        return array_map(
            static fn(string $problem): string => sprintf('%s %s = %s: %s', $parameter->in, $parameter->name, implode(', ', $items), $problem),
            $first ?? [],
        );
    }

    /**
     * @return list<string>
     */
    private function checkBody(SpecOperation $operation, RequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        if (!$operation->hasBody()) {
            return $raw === '' ? [] : ['body: the operation takes none'];
        }
        if ($raw === '') {
            return $operation->bodyRequired() ? ['body: required, and not sent'] : [];
        }
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($type !== SpecOperation::JSON) {
            return [sprintf('header Content-Type: "%s", not %s', $type, SpecOperation::JSON)];
        }
        try {
            $body = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ['body: not JSON: ' . $e->getMessage()];
        }

        return array_map(static fn(string $problem): string => 'body ' . $problem, $operation->validateBody($body));
    }

    /**
     * The status and reason a credential is refused with, or null when the
     * operation takes it.
     *
     * @return array{int, string}|null
     */
    private function authorise(SpecOperation $operation, ?string $secret): ?array
    {
        if ($secret === null) {
            return $operation->anonymous() ? null : [self::UNAUTHORIZED, 'header Authorization: the operation needs a credential, and none was sent'];
        }
        $scheme = $this->state->credentials[$secret] ?? null;
        foreach (self::CREDENTIAL_PREFIXES as $prefix => $prefixed) {
            if ($scheme === null && str_starts_with($secret, $prefix)) {
                $scheme = $prefixed;
            }
        }
        if ($scheme === null) {
            return [self::UNAUTHORIZED, 'header Authorization: not a credential Pixonaut issues'];
        }
        foreach ($operation->security as $requirement) {
            if ($requirement === [$scheme]) {
                return null;
            }
        }

        return [self::FORBIDDEN, sprintf('header Authorization: a %s, which the operation does not take', $scheme)];
    }

    private function answerFor(SpecOperation $operation, ?Fault $fault, string $requestId): ResponseInterface
    {
        if ($fault?->kind === Fault::STATUS) {
            $headers = $fault->retryAfter === null ? [] : ['Retry-After' => (string) $fault->retryAfter];

            return $this->documented($operation, $fault->status, (string) $fault->code, $requestId, $headers);
        }
        if ($fault?->kind === Fault::NOT_JSON) {
            return new Response(self::OK, ['Content-Type' => 'text/html', 'X-Request-Id' => $requestId], '<html><body>Bad gateway</body></html>');
        }

        if (($this->state->answers[$operation->id] ?? []) === []) {
            $this->answerExample($operation->id, $operation->successStatuses()[0] ?? self::OK);
        }
        $set = array_shift($this->state->answers[$operation->id]) ?? throw new \LogicException('No answer for ' . $operation->id . '.');
        $headers = $set['headers'] + ['X-Request-Id' => $requestId];
        if ($fault?->kind === Fault::DEPRECATED) {
            $headers['Deprecation'] = (string) $fault->deprecation;
            if ($fault->sunset !== null) {
                $headers['Sunset'] = $fault->sunset;
            }
        }
        $this->checkAnswer($operation, $set['status'], $set['body'], $headers);

        return new Response($set['status'], $headers, $set['body']);
    }

    /**
     * Holds an answer the fake gives to the spec, so a fixture or an
     * example that drifted from the schema is a violation too.
     *
     * @param array<string, string> $headers
     */
    private function checkAnswer(SpecOperation $operation, int $status, string $body, array $headers): void
    {
        if (!in_array($status, $operation->statuses(), true)) {
            $this->violation(sprintf('%s answer %d: the spec documents no such status', $operation->id, $status));

            return;
        }
        $type = '';
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $type = strtolower(trim(explode(';', $value)[0]));
            }
        }
        $documented = $operation->mediaTypes($status);
        if ($documented === []) {
            if ($body !== '') {
                $this->violation(sprintf('%s answer %d: the spec documents no body for it', $operation->id, $status));
            }

            return;
        }
        if ($body === '') {
            $this->violation(sprintf('%s answer %d: the spec documents a body for it, and none was given', $operation->id, $status));

            return;
        }
        if (!in_array($type, $documented, true)) {
            $this->violation(sprintf('%s answer %d: Content-Type "%s", not one the spec documents (%s)', $operation->id, $status, $type, implode(', ', $documented)));

            return;
        }
        if ($type !== SpecOperation::JSON) {
            return;
        }
        try {
            $json = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->violation(sprintf('%s answer %d: not JSON: %s', $operation->id, $status, $e->getMessage()));

            return;
        }
        foreach ($operation->validateAnswer($status, $json) as $problem) {
            $this->violation(sprintf('%s answer %d %s', $operation->id, $status, $problem));
        }
    }

    /**
     * An error answer the operation documents: the spec's example for the
     * code when it has one.
     *
     * @param array<string, string> $headers
     */
    private function documented(SpecOperation $operation, int $status, string $code, string $requestId, array $headers = []): ResponseInterface
    {
        $example = $operation->answerExample($status, $code);
        $body = $example instanceof stdClass ? $example : (object) ['error' => $code];
        $encoded = json_encode($body, self::JSON_FLAGS);
        $headers = ['Content-Type' => SpecOperation::JSON] + $headers;
        $this->checkAnswer($operation, $status, $encoded, $headers);

        return new Response($status, $headers + ['X-Request-Id' => $requestId], $encoded);
    }

    /**
     * An answer for a request the fake refuses before it reaches an
     * operation's own logic; the request is already a violation.
     *
     * @param array<string, mixed> $details
     */
    private function error(int $status, string $code, string $requestId, array $details = []): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => SpecOperation::JSON, 'X-Request-Id' => $requestId],
            json_encode(['error' => $code] + $details, self::JSON_FLAGS),
        );
    }

    private function respond(SpecOperation $operation, string $method, string $target, bool $replayed, ResponseInterface $response): ResponseInterface
    {
        $this->record($operation, $method, $target, $response->getStatusCode(), $replayed);

        return $response;
    }

    private function record(SpecOperation $operation, string $method, string $target, int $status, bool $replayed): void
    {
        $this->state->log[] = ['operation' => $operation->id, 'method' => $method, 'target' => $target, 'status' => $status, 'replayed' => $replayed];
    }

    private function violation(string $problem): void
    {
        $this->state->violations[] = $problem;
    }

    private function takeFault(string $operation): ?Fault
    {
        foreach ([$operation, self::ANY_OPERATION] as $key) {
            if (($this->state->faults[$key] ?? []) !== []) {
                return array_shift($this->state->faults[$key]);
            }
        }

        return null;
    }

    private static function bearer(RequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            return null;
        }

        return preg_match('/^Bearer (\S+)\z/', $header, $found) === 1 ? $found[1] : '';
    }

    public static function binary(string $operation, string $type): string
    {
        return self::BINARY_PREFIX . $type . ' of ' . $operation;
    }

    /**
     * The query's values by name, decoded, in the order sent.
     *
     * @return array<string, list<string>>
     */
    private static function query(string $query): array
    {
        $values = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $values[rawurldecode($name)][] = rawurldecode($value);
        }

        return $values;
    }

    /**
     * The JSON values a parameter sent as text may stand for: the text, and
     * the number, boolean or null it spells; for a list, each item read the
     * same way.
     *
     * @param list<string> $items
     *
     * @return list<mixed>
     */
    private static function candidates(array $items, bool $listable): array
    {
        $readings = array_map(self::readings(...), $items);
        $candidates = count($items) === 1 ? $readings[0] : [];
        if ($listable) {
            foreach (['string', 'integer', 'double', 'boolean'] as $type) {
                $list = [];
                foreach ($readings as $item) {
                    $typed = array_values(array_filter($item, static fn(mixed $reading): bool => gettype($reading) === $type));
                    if ($typed === []) {
                        continue 2;
                    }
                    $list[] = $typed[0];
                }
                $candidates[] = $list;
            }
        }

        return $candidates;
    }

    /**
     * @return list<mixed>
     */
    private static function readings(string $text): array
    {
        $readings = [$text];
        if (preg_match('/^-?(0|[1-9]\d*)\z/', $text) === 1) {
            $readings[] = (int) $text;
        }
        if (is_numeric($text)) {
            $readings[] = (float) $text;
        }
        if ($text === 'true' || $text === 'false') {
            $readings[] = $text === 'true';
        }
        if ($text === 'null') {
            $readings[] = null;
        }

        return $readings;
    }
}
