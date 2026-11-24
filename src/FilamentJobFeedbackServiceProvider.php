<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback;

use Illuminate\Support\ServiceProvider;

class FilamentJobFeedbackServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FilamentJobFeedback::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-job-feedback');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'filament-job-feedback');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/filament-job-feedback'),
        ], ['filament-job-feedback', 'filament-job-feedback-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/filament-job-feedback'),
        ], ['filament-job-feedback', 'filament-job-feedback-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/filament-job-feedback'),
        ], ['filament-job-feedback', 'filament-job-feedback-assets']);
    }
}
