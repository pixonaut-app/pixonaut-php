<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Docs;

use RuntimeException;

/**
 * Runs the examples in `docs/examples/` as a host would: each in its own
 * PHP process with an autoloader prepended, against a Pixonaut at
 * `$apiUrl`, the keys `connect.php` stores kept in a file of their own.
 * The webhook endpoint is served by PHP's built-in server and sent
 * deliveries over a socket.
 *
 * It names no SDK class, so it runs a prefixed copy of the examples, with
 * its prefixed autoloader, just the same.
 */
final class Examples
{
    private const HOST = '127.0.0.1';

    private const WEBHOOK = 'webhook.php';

    /** How long a server gets to start listening, and an example to finish. */
    private const TIMEOUT_SECONDS = 30;

    private const POLL_MICROSECONDS = 50_000;

    private const SUCCESS = 0;

    /** Readable by its owner only, as a host keeps the keys and snapshots. */
    private const PRIVATE_DIRECTORY_MODE = 0700;

    /** Random bytes in the storage directory's name, so no two runs share it. */
    private const NAME_BYTES = 8;

    /** Where the examples keep what they store, removed by `cleanUp()`. */
    public readonly string $storage;

    /** Not there until `connect.php` creates it. */
    public readonly string $keysFile;

    public readonly string $snapshotDirectory;

    public function __construct(
        private readonly string $directory,
        private readonly string $autoload,
        private readonly string $apiUrl,
    ) {
        $this->storage = sys_get_temp_dir() . '/pixonaut-examples-' . bin2hex(random_bytes(self::NAME_BYTES));
        if (!mkdir($this->storage, self::PRIVATE_DIRECTORY_MODE)) {
            throw new RuntimeException('No directory for the examples to store in.');
        }
        $this->keysFile = $this->storage . '/pixonaut-keys.json';
        $this->snapshotDirectory = $this->storage . '/snapshots';
    }

    /**
     * Runs an example to its end.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $environment Over the settings the examples are run with.
     *
     * @return string What it printed.
     *
     * @throws RuntimeException When it fails, with what it wrote to standard error.
     */
    public function run(string $example, array $arguments = [], array $environment = []): string
    {
        $output = (string) tempnam(sys_get_temp_dir(), 'pixonaut-example-out-');
        $errors = (string) tempnam(sys_get_temp_dir(), 'pixonaut-example-err-');
        $process = proc_open(
            [PHP_BINARY, '-d', 'auto_prepend_file=' . $this->autoload, $this->path($example), ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $errors, 'w']],
            $pipes,
            $this->directory,
            [...$this->environment(), ...$environment],
        );
        if (!is_resource($process)) {
            throw new RuntimeException($example . ' did not start.');
        }
        fclose($pipes[0]);
        $status = $this->wait($process, $example);
        $printed = (string) file_get_contents($output);
        $written = (string) file_get_contents($errors);
        @unlink($output);
        @unlink($errors);
        if ($status !== self::SUCCESS || $written !== '') {
            throw new RuntimeException(sprintf("%s exited %d:\n%s%s", $example, $status, $printed, $written));
        }

        return $printed;
    }

    /**
     * Serves the webhook endpoint for as long as `$deliver` runs.
     *
     * @template T
     *
     * @param \Closure(WebhookEndpoint): T $deliver
     *
     * @return T
     */
    public function serveWebhook(\Closure $deliver): mixed
    {
        $port = self::freePort();
        $log = (string) tempnam(sys_get_temp_dir(), 'pixonaut-webhook-log-');
        // The built-in server prepends nothing to its router script, so the
        // router loads the autoloader, then the endpoint.
        $router = (string) tempnam(sys_get_temp_dir(), 'pixonaut-webhook-router-');
        file_put_contents($router, sprintf("<?php\nrequire %s;\nrequire %s;\n", var_export($this->autoload, true), var_export($this->path(self::WEBHOOK), true)));
        $process = proc_open(
            [PHP_BINARY, '-S', self::HOST . ':' . $port, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $this->directory,
            $this->environment(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The webhook endpoint did not start.');
        }
        try {
            $this->waitUntilListening($port, $process, $log);

            return $deliver(new WebhookEndpoint(self::HOST, $port, $log));
        } finally {
            proc_terminate($process);
            proc_close($process);
            @unlink($log);
            @unlink($router);
        }
    }

    public function cleanUp(): void
    {
        self::remove($this->storage);
    }

    private function path(string $example): string
    {
        $path = $this->directory . '/' . $example;
        if (!is_file($path)) {
            throw new RuntimeException('No example ' . $path . '.');
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        $environment = getenv();
        $environment['PIXONAUT_API_URL'] = $this->apiUrl;
        $environment['PIXONAUT_KEYS_FILE'] = $this->keysFile;
        $environment['PIXONAUT_SNAPSHOT_DIR'] = $this->snapshotDirectory;

        return $environment;
    }

    /**
     * @param resource $process
     */
    private function wait($process, string $example): int
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                proc_close($process);

                return $status['exitcode'];
            }
            usleep(self::POLL_MICROSECONDS);
        }
        proc_terminate($process);
        proc_close($process);

        throw new RuntimeException($example . ' did not finish in time.');
    }

    /**
     * @param resource $process
     */
    private function waitUntilListening(int $port, $process, string $log): void
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen(self::HOST, $port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(self::POLL_MICROSECONDS);
        }

        throw new RuntimeException('The webhook endpoint is not listening: ' . file_get_contents($log));
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                self::remove($path . '/' . $entry);
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://' . self::HOST . ':0');
        if ($socket === false) {
            throw new RuntimeException('No free loopback port.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
