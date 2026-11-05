<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Http;

use DateTimeImmutable;
use DateTimeZone;

/**
 * @internal
 */
final class HttpDate
{
    /** The IMF-fixdate format of RFC 9110, e.g. `Sun, 06 Nov 1994 08:49:37 GMT`. */
    private const FORMAT = 'D, d M Y H:i:s \G\M\T';

    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!' . self::FORMAT, trim($value), new DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }
}
