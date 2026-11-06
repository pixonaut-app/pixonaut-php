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
     * A model as its fields, `{}` when it has none set.
     *
     * @return array<string, mixed>|stdClass
     */
    public static function model(Model $model): array|stdClass
    {
        $data = $model->toArray();

        return $data === [] ? new stdClass() : $data;
    }

    /**
     * A JSON object, `{}` when it is empty.
     *
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>|stdClass
     */
    public static function object(array $object): array|stdClass
    {
        return $object === [] ? new stdClass() : $object;
    }

    /**
     * A value whose schema is a union: models become their fields, arrays
     * are encoded item by item.
     *
     * @param bool $emptyIsObject Whether an empty array is the union's object
     *                            variant, sent as `{}`, rather than a list.
     */
    public static function value(mixed $value, bool $emptyIsObject = false): mixed
    {
        if ($value instanceof Model) {
            return self::model($value);
        }
        if ($value === [] && $emptyIsObject) {
            return new stdClass();
        }
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::value($item), $value);
        }

        return $value;
    }
}
