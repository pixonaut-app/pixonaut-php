<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator\Surface\Fixtures;

trait Named
{
    public function name(): string
    {
        return static::class;
    }
}
