<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Orchestra\Testbench\Foundation\Config;
use Orchestra\Testbench\Workbench\Workbench;
use SkyRaptor\FilamentJobFeedback\Actions\JobAction;

$builder = Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware()
    ->withExceptions();

if (defined('TESTBENCH_CORE')) {
    return $builder->create();
}

$config = Config::loadFromYaml(dirname(__DIR__, 2));
$app = $builder
    // Workbench registers the routes; Laravel still needs to refresh named-route lookups.
    ->withRouting(using: static function (): void {})
    ->withProviders($config->getExtraAttributes()['providers'] ?? [])
    ->create();

if ($config->getWorkbenchDiscoversAttributes()['views'] ?? false) {
    $app->booted(static function () use ($app): void {
        $app->make('view')->addLocation(dirname(__DIR__, 2).'/workbench/resources/views');
    });
}

Workbench::startWithProviders($app, $config);
Workbench::discoverRoutes($app, $config);

$app->booted(static function (): void {
    $slot = config()->string('workbench-browser.submission_slot');

    if ($slot === '') {
        return;
    }

    JobAction::configureUsing(static function (JobAction $action) use ($slot): void {
        if ($action->getName() !== 'rebuild') {
            return;
        }

        $action->before(static function () use ($slot): void {
            // Both requests must pass Filament's disabled check before either can claim the job.
            if (! touch(storage_path("browser-submission-{$slot}"))) {
                throw new RuntimeException("Unable to signal that browser submission {$slot} is ready.");
            }

            $deadline = hrtime(true) + 10_000_000_000;

            while (! file_exists(storage_path('browser-release-submissions'))) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for the browser test to release submissions.');
                }

                usleep(10_000);
            }
        });
    });
});

return $app;
