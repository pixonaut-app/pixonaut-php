<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Generator\Surface\Fixtures;

interface Shape extends \Countable
{
    public const SIDES = 0;

    public function area(): float;
}
