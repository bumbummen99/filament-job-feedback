<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobAction;

use Illuminate\Support\ServiceProvider;

class FilamentJobActionServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FilamentJobAction::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-job-action');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'filament-job-action');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/filament-job-action'),
        ], ['filament-job-action', 'filament-job-action-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/filament-job-action'),
        ], ['filament-job-action', 'filament-job-action-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/filament-job-action'),
        ], ['filament-job-action', 'filament-job-action-assets']);
    }
}
