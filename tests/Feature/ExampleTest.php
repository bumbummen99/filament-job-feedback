<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use SkyRaptor\FilamentJobFeedback\FilamentJobFeedback;
use SkyRaptor\FilamentJobFeedback\FilamentJobFeedbackServiceProvider;

it('resolves the singleton', function () {
    expect(app(FilamentJobFeedback::class))->toBeInstanceOf(FilamentJobFeedback::class);
});

it('returns the same instance from the container', function () {
    expect(app(FilamentJobFeedback::class))->toBe(app(FilamentJobFeedback::class));
});

it('loads the package translations', function () {
    expect(trans('filament-job-feedback::messages.placeholder'))->toBe('FilamentJobFeedback placeholder translation.');
});

it('loads the package views', function () {
    expect(view('filament-job-feedback::placeholder')->render())->toContain('FilamentJobFeedback placeholder view.');
});

it('registers the package publish groups', function (string $resource) {
    $paths = ServiceProvider::pathsToPublish(
        FilamentJobFeedbackServiceProvider::class,
        'filament-job-feedback-'.$resource,
    );

    $destination = match ($resource) {
        'views' => resource_path('views/vendor/filament-job-feedback'),
        'lang' => app()->langPath('vendor/filament-job-feedback'),
        'assets' => public_path('vendor/filament-job-feedback'),
    };

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths))->toBe([$destination])
        ->and(ServiceProvider::pathsToPublish(
            FilamentJobFeedbackServiceProvider::class,
            'filament-job-feedback',
        ))->toHaveCount(3)->toMatchArray($paths);
})->with(['views', 'lang', 'assets']);
