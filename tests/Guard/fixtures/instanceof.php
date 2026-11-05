<?php

declare(strict_types=1);

function matches(object $object, string $class): bool
{
    return $object instanceof $class;
}
