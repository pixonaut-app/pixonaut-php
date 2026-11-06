<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

use Generator;
use IteratorAggregate;
use LogicException;
use Pixonaut\Sdk\Exception\ApiException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A `text/event-stream` answer, read as the caller iterates: each step reads
 * the body until the next event is complete, so a long answer is never held
 * whole.
 *
 * Whether events arrive while Pixonaut is still sending depends on the
 * PSR-18 client: one that buffers the whole response before returning it
 * (most do by default; Guzzle streams with the `stream` request option)
 * hands every event over at once, after the answer has ended.
 *
 * The stream is read once. An event the answer was cut off in the middle of
 * is not yielded, as the SSE format prescribes. A body that fails while it
 * is read, or stops giving bytes before it has ended (a read timeout), ends
 * the iteration with an `ApiException` `transport_failed`. The body is
 * closed when the iteration ends, also when the caller stops early.
 *
 * @implements IteratorAggregate<int, StreamEvent>
 */
final class EventStream implements IteratorAggregate
{
    /** How much of the body one read asks for. */
    private const CHUNK_BYTES = 8192;

    /** A UTF-8 byte order mark, which the format allows before the first line. */
    private const BOM = "\xEF\xBB\xBF";

    private const LINE_FEED = "\n";

    private const CARRIAGE_RETURN = "\r";

    private bool $started = false;

    /**
     * @param StreamInterface|string $body      The answer's body, unread.
     * @param string|null            $requestId The answer's `X-Request-Id`.
     * @param string                 $operation The operation it answers, for the error a failed read raises.
     */
    public function __construct(
        private readonly StreamInterface|string $body,
        public readonly ?string $requestId = null,
        public readonly string $operation = '',
    ) {}

    /**
     * @return Generator<int, StreamEvent>
     *
     * @throws LogicException When the stream was already read.
     * @throws ApiException   `transport_failed`, not retryable, when the body fails or stalls before it has ended:
     *                        the events read so far may have had effects, so the request is not sent again.
     */
    public function getIterator(): Generator
    {
        if ($this->started) {
            throw new LogicException('An event stream can be read once.');
        }
        $this->started = true;

        $data = '';
        $type = '';
        $id = null;
        $retry = null;
        foreach ($this->lines() as $line) {
            if ($line === '') {
                // A blank line ends the event; one without `data` dispatches nothing.
                if ($data !== '') {
                    yield new StreamEvent($type === '' ? StreamEvent::DEFAULT_TYPE : $type, substr($data, 0, -1), $id, $retry);
                }
                $data = '';
                $type = '';
                continue;
            }
            if ($line[0] === ':') {
                continue;
            }
            $colon = strpos($line, ':');
            $field = $colon === false ? $line : substr($line, 0, $colon);
            $value = $colon === false ? '' : substr($line, $colon + 1);
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }
            match ($field) {
                'data' => $data .= $value . self::LINE_FEED,
                'event' => $type = $value,
                'id' => $id = str_contains($value, "\0") ? $id : $value,
                'retry' => $retry = ctype_digit($value) ? (int) $value : $retry,
                default => null,
            };
        }
    }

    /**
     * The body's lines, without their ends (CRLF, LF or CR). A last line
     * the body does not end is left out: it belongs to a cut-off event.
     *
     * @return Generator<int, string>
     */
    private function lines(): Generator
    {
        $buffer = '';
        $bomChecked = false;
        // A CR that ended the last read may be the first half of a CRLF.
        $skipLineFeed = false;
        foreach ($this->chunks() as [$chunk, $ended]) {
            $buffer .= $chunk;
            if (!$bomChecked) {
                if (strlen($buffer) < strlen(self::BOM) && !$ended) {
                    continue;
                }
                if (str_starts_with($buffer, self::BOM)) {
                    $buffer = substr($buffer, strlen(self::BOM));
                }
                $bomChecked = true;
            }
            if ($skipLineFeed && $buffer !== '') {
                if ($buffer[0] === self::LINE_FEED) {
                    $buffer = substr($buffer, 1);
                }
                $skipLineFeed = false;
            }
            while (true) {
                $end = strcspn($buffer, self::CARRIAGE_RETURN . self::LINE_FEED);
                if ($end === strlen($buffer)) {
                    break;
                }
                $length = 1;
                if ($buffer[$end] === self::CARRIAGE_RETURN) {
                    if ($end + 1 === strlen($buffer)) {
                        // The line has ended; the next read may start with the LF of a CRLF.
                        $skipLineFeed = true;
                    } elseif ($buffer[$end + 1] === self::LINE_FEED) {
                        $length = 2;
                    }
                }
                yield substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + $length);
            }
        }
    }

    /**
     * Each piece of the body as it is read, and whether it was the last.
     *
     * @return Generator<int, array{string, bool}>
     */
    private function chunks(): Generator
    {
        if (is_string($this->body)) {
            yield [$this->body, true];

            return;
        }
        try {
            while (true) {
                try {
                    $chunk = $this->body->read(self::CHUNK_BYTES);
                    $ended = $this->body->eof();
                } catch (RuntimeException $e) {
                    throw $this->failed($e->getMessage(), $e);
                }
                if ($chunk === '') {
                    // A blocking stream reads nothing before its end only when a read timed out.
                    if (!$ended) {
                        throw $this->failed('The body gave no bytes before it ended.');
                    }
                    break;
                }
                yield [$chunk, false];
                if ($ended) {
                    break;
                }
            }
            yield ['', true];
        } finally {
            $this->body->close();
        }
    }

    /**
     * The error a body that failed or stalled while being read ends the
     * iteration with.
     */
    private function failed(string $message, ?RuntimeException $cause = null): ApiException
    {
        return new ApiException($this->operation, 0, ApiException::TRANSPORT_FAILED, false, null, $this->requestId, ['message' => $message], $cause);
    }
}
