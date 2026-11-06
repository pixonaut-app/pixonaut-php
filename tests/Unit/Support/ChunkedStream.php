<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Unit\Support;

use LogicException;
use Psr\Http\Message\StreamInterface;

/**
 * A body that arrives in pieces, one per read, recording how far it was
 * read: a response a streaming PSR-18 client returns before it has ended.
 * A `null` piece is a read that timed out, a `Throwable` one that failed.
 */
final class ChunkedStream implements StreamInterface
{
    /** How many pieces were read. */
    public int $reads = 0;

    public bool $closed = false;

    /**
     * @param list<string|\Throwable|null> $chunks
     */
    public function __construct(
        private array $chunks,
    ) {}

    public function __toString(): string
    {
        throw new LogicException('The body was read whole.');
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return $this->chunks === [];
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new LogicException('Not seekable.');
    }

    public function rewind(): void
    {
        throw new LogicException('Not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new LogicException('Not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($this->closed) {
            throw new LogicException('Read after close.');
        }
        ++$this->reads;
        $chunk = array_shift($this->chunks);
        if ($chunk instanceof \Throwable) {
            throw $chunk;
        }

        return (string) $chunk;
    }

    public function getContents(): string
    {
        throw new LogicException('The body was read whole.');
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
