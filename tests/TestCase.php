<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SkyRaptor\FilamentJobFeedback\FilamentJobFeedbackServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FilamentJobFeedbackServiceProvider::class,
        ];
    }
}
