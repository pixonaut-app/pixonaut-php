<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

// The installed packages and the integration's own code, prefixed into
// build/; Composer's autoloader is dumped again there afterwards.
return [
    'prefix' => 'Pixonaut\\Fixture\\Vendor',
    'finders' => [
        Finder::create()->files()->in('app'),
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/LICENSE|.*\\.md|.*\\.dist|Makefile|composer\\.lock/')
            ->exclude(['doc', 'test', 'tests', 'Tests'])
            ->in('vendor'),
        Finder::create()->append(['composer.json']),
    ],
];
