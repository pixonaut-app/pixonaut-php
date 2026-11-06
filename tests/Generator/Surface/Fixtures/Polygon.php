<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator\Surface\Fixtures;

abstract class Polygon
{
    abstract public function area(): float;

    final public function describe(?Unit &$unit): string
    {
        return 'a polygon';
    }
}
