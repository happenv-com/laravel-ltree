<?php

declare(strict_types=1);

namespace Happenv\Ltree\Tests;

use Happenv\Ltree\LaravelLtreeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [LaravelLtreeServiceProvider::class];
    }
}
