<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Spec;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A CDN in memory: serves the files it was given by URL, 404 for the rest.
 */
final class FakeCdn implements ClientInterface
{
    public const ORIGIN = 'https://cdn.test';

    private const HTTP_OK = 200;

    private const HTTP_NOT_FOUND = 404;

    /** @var array<string, array{int, string}> */
    private array $files = [];

    /** @var list<string> */
    public array $requested = [];

    /**
     * Publishes API release `$version` with an `integrity.json` that vouches
     * for the files as given.
     */
    public function publish(string $version, string $spec, string $vectors): void
    {
        $this->serve($version, 'openapi.json', $spec);
        $this->serve($version, 'webhook-signature-vectors.json', $vectors);
        $this->serve($version, 'integrity.json', json_encode([
            'version' => $version,
            'files' => [
                'openapi.json' => self::sri($spec),
                'webhook-signature-vectors.json' => self::sri($vectors),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Serves `$body` for one file of a release, replacing what was there.
     */
    public function serve(string $version, string $name, string $body, int $status = self::HTTP_OK): void
    {
        $this->files[self::ORIGIN . "/api/{$version}/{$name}"] = [$status, $body];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $this->requested[] = $url;
        [$status, $body] = $this->files[$url] ?? [self::HTTP_NOT_FOUND, 'Not Found'];

        return new Response($status, [], $body);
    }

    private static function sri(string $bytes): string
    {
        return 'sha384-' . base64_encode(hash('sha384', $bytes, true));
    }
}
