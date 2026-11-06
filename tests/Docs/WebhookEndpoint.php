<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Docs;

use RuntimeException;

/**
 * The served webhook example, sent deliveries signed as Pixonaut signs them.
 */
final class WebhookEndpoint
{
    private const SCHEME = 'v1';

    private const ALGORITHM = 'sha256';

    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $log,
    ) {}

    /**
     * Posts a body signed with `$secret` at `$timestamp`, and answers the
     * endpoint's status.
     */
    public function deliver(string $body, string $secret, int $timestamp): int
    {
        $signature = self::SCHEME . '=' . hash_hmac(self::ALGORITHM, $timestamp . '.' . $body, $secret);

        return $this->post($body, ['x-pixonaut-timestamp' => (string) $timestamp, 'x-pixonaut-signature' => $signature]);
    }

    /**
     * Posts a body with the headers given as they are.
     *
     * @param array<string, string> $headers
     */
    public function post(string $body, array $headers): int
    {
        $socket = @fsockopen($this->host, $this->port, $errorCode, $errorMessage, self::TIMEOUT_SECONDS);
        if ($socket === false) {
            throw new RuntimeException('The webhook endpoint refused the connection: ' . $errorMessage);
        }
        $lines = ['POST / HTTP/1.1', 'Host: ' . $this->host . ':' . $this->port, 'Content-Type: application/json', 'Content-Length: ' . strlen($body), 'Connection: close'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n" . $body);
        $statusLine = (string) fgets($socket);
        fclose($socket);
        if (preg_match('#^HTTP/\S+ (\d{3})#', $statusLine, $match) !== 1) {
            throw new RuntimeException('The webhook endpoint answered no status: ' . $statusLine);
        }

        return (int) $match[1];
    }

    /**
     * What the endpoint has logged so far.
     */
    public function log(): string
    {
        return (string) file_get_contents($this->log);
    }
}
