<?php

declare(strict_types=1);

function run(string $class, callable $callback): void
{
    call_user_func('Some' . '\\Thing::run');
    call_user_func_array(['Some' . '\\Thing', 'run'], []);
    call_user_func([$class, 'create']);
    call_user_func($callback);
    call_user_func_array(args: [], callback: $callback);
}
