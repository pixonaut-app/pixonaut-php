<?php

declare(strict_types=1);

namespace Example;

use Pixonaut\Sdk\Tests\Guard\DynamicClassNameFinder;

function allowed(object $object): array
{
    return [
        new DynamicClassNameFinder(),
        new class {},
        DynamicClassNameFinder::class,
        $object::class,
        class_exists(DynamicClassNameFinder::class),
        is_a($object, DynamicClassNameFinder::class),
        call_user_func(static fn(): int => 1),
        call_user_func([$object, 'run']),
    ];
}
