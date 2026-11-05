<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

/**
 * @internal
 */
final class Uuid
{
    private const BYTES = 16;

    /** Byte 6 carries the version in its high nibble (RFC 9562). */
    private const VERSION_BYTE = 6;

    private const VERSION_4 = 0x40;

    /** Byte 8 carries the variant in its two high bits. */
    private const VARIANT_BYTE = 8;

    private const VARIANT_RFC = 0x80;

    private const LOW_NIBBLE = 0x0f;

    private const LOW_SIX_BITS = 0x3f;

    /** Hex digits per group of the textual form: 8-4-4-4-12. */
    private const GROUPS = [8, 4, 4, 4, 12];

    public static function v4(): string
    {
        $bytes = random_bytes(self::BYTES);
        $bytes[self::VERSION_BYTE] = chr((ord($bytes[self::VERSION_BYTE]) & self::LOW_NIBBLE) | self::VERSION_4);
        $bytes[self::VARIANT_BYTE] = chr((ord($bytes[self::VARIANT_BYTE]) & self::LOW_SIX_BITS) | self::VARIANT_RFC);

        $hex = bin2hex($bytes);
        $groups = [];
        $offset = 0;
        foreach (self::GROUPS as $length) {
            $groups[] = substr($hex, $offset, $length);
            $offset += $length;
        }

        return implode('-', $groups);
    }
}
