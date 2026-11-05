<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Model;

use stdClass;

/**
 * Turns model values into JSON-ready values for `toArray()`.
 */
final class Encode
{
    /**
     * A model becomes its array, an empty one `{}` so that it is still sent
     * as an object; arrays are encoded item by item.
     */
    public static function value(mixed $value): mixed
    {
        if ($value instanceof Model) {
            $data = $value->toArray();

            return $data === [] ? new stdClass() : $data;
        }
        if (is_array($value)) {
            return array_map(self::value(...), $value);
        }

        return $value;
    }

    /**
     * A JSON object of values, `{}` when it is empty.
     *
     * @param array<string, mixed> $map
     */
    public static function map(array $map): mixed
    {
        return $map === [] ? new stdClass() : array_map(self::value(...), $map);
    }
}
