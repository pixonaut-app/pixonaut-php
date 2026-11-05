<?php

declare(strict_types=1);

namespace Example;

use Pixonaut\Sdk\Tests\Guard\DynamicClassNameFinder;

final class Allowed
{
    /**
     * @return list<mixed>
     */
    public function all(object $object): array
    {
        return [
            new DynamicClassNameFinder(),
            new class {},
            DynamicClassNameFinder::class,
            $object::class,
            $object instanceof DynamicClassNameFinder,
            class_exists(DynamicClassNameFinder::class),
            class_exists(autoload: false, class: DynamicClassNameFinder::class),
            is_a($object, DynamicClassNameFinder::class),
            is_a(class: DynamicClassNameFinder::class, object_or_class: $object),
            call_user_func(static fn(): int => 1),
            call_user_func(function (): int {
                return 1;
            }),
            call_user_func($this->one(...)),
            call_user_func([$this, 'one']),
            call_user_func([self::class, 'two']),
            call_user_func_array(args: [], callback: [DynamicClassNameFinder::class, 'create']),
        ];
    }

    public function one(): int
    {
        return 1;
    }

    public static function two(): int
    {
        return 2;
    }
}
