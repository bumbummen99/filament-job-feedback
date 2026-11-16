<?php

declare(strict_types=1);

use SkyRaptor\FilamentJobAction\FilamentJobAction;

it('resolves the singleton', function () {
    expect(app(FilamentJobAction::class))->toBeInstanceOf(FilamentJobAction::class);
});

it('returns the same instance from the container', function () {
    expect(app(FilamentJobAction::class))->toBe(app(FilamentJobAction::class));
});

it('loads the package translations', function () {
    expect(trans('filament-job-action::messages.placeholder'))->toBe('FilamentJobAction placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('filament-job-action::placeholder'))->toBeTrue();
});
