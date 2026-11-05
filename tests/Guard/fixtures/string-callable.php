<?php

declare(strict_types=1);

function run(): void
{
    call_user_func('Some' . '\\Thing::run');
    call_user_func_array(['Some' . '\\Thing', 'run'], []);
}
