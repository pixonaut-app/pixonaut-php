<?php

declare(strict_types=1);

function build(string $class): object
{
    return $class::create();
}
