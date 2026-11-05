<?php

declare(strict_types=1);

function version(string $class): string
{
    return $class::VERSION;
}
