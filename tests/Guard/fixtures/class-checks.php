<?php

declare(strict_types=1);

function check(object $object, string $class): bool
{
    return class_exists($class)
        && is_a($object, 'Some' . 'Thing')
        && is_subclass_of($object, $class);
}
