<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Fake;

use Closure;
use RuntimeException;

/**
 * The fake's state kept in a file, for the HTTP router, which answers each
 * request in a fresh process, and for the test that scripts it.
 */
final class FakeStateFile
{
    public function __construct(private readonly string $path) {}

    /**
     * Runs the closure on the fake over the stored state, holding the file
     * locked, and stores the state again even when the closure throws: a
     * fault it consumed or an answer it made must not be forgotten.
     *
     * @template T
     *
     * @param Closure(FakePixonaut): T $change
     *
     * @return T
     */
    public function update(Closure $change): mixed
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('The fake\'s state file cannot be opened.');
        }
        $fake = new FakePixonaut(self::decode((string) stream_get_contents($handle)));
        try {
            return $change($fake);
        } finally {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, serialize($fake->state()));
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function read(): FakeState
    {
        return self::decode((string) @file_get_contents($this->path));
    }

    private static function decode(string $stored): FakeState
    {
        $state = $stored === '' ? null : unserialize($stored, ['allowed_classes' => [FakeState::class, Fault::class]]);

        return $state instanceof FakeState ? $state : new FakeState();
    }
}
