<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Fake;

use Closure;
use RuntimeException;

/**
 * The fake served by PHP's built-in server on a loopback port, started and
 * stopped by a test.
 */
final class FakeServer
{
    private const ROUTER = __DIR__ . '/router.php';

    private const HOST = '127.0.0.1';

    /** How long the server gets to start listening. */
    private const START_TIMEOUT_SECONDS = 10;

    private const POLL_MICROSECONDS = 50_000;

    /** The router decodes the whole spec on each request. */
    private const MEMORY_LIMIT = '1G';

    /** @var resource */
    private $process;

    private function __construct(
        public readonly int $port,
        private readonly string $stateFile,
        private readonly string $errorLog,
    ) {}

    /**
     * Starts a server on `FAKE_PIXONAUT_PORT`, or on a free port when it is
     * not set.
     */
    public static function start(): self
    {
        $configured = getenv('FAKE_PIXONAUT_PORT');
        $port = is_string($configured) && ctype_digit($configured) ? (int) $configured : self::freePort();
        $state = (string) tempnam(sys_get_temp_dir(), 'fake-pixonaut-state-');
        $log = (string) tempnam(sys_get_temp_dir(), 'fake-pixonaut-log-');
        $server = new self($port, $state, $log);

        $environment = getenv();
        $environment['FAKE_PIXONAUT_STATE'] = $state;
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=' . self::MEMORY_LIMIT, '-S', self::HOST . ':' . $port, self::ROUTER],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The fake server did not start.');
        }
        $server->process = $process;
        $server->waitUntilListening();

        return $server;
    }

    public function baseUrl(): string
    {
        return 'http://' . self::HOST . ':' . $this->port;
    }

    /**
     * What the fake has seen so far, from its state file.
     */
    public function state(): FakeState
    {
        return (new FakeStateFile($this->stateFile))->read();
    }

    /**
     * Scripts the served fake: answers, faults, keys in flight.
     *
     * @param Closure(FakePixonaut): void $script
     */
    public function script(Closure $script): void
    {
        (new FakeStateFile($this->stateFile))->update($script);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->stateFile);
        @unlink($this->errorLog);
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen(self::HOST, $this->port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            if (!proc_get_status($this->process)['running']) {
                break;
            }
            usleep(self::POLL_MICROSECONDS);
        }
        $log = (string) file_get_contents($this->errorLog);
        $this->stop();

        throw new RuntimeException('The fake server is not listening on port ' . $this->port . ': ' . $log);
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
