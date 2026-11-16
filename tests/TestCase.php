<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobAction\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SkyRaptor\FilamentJobAction\FilamentJobActionServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FilamentJobActionServiceProvider::class,
        ];
    }
}
