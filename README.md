<div align="center">
    <h1>Filament Job Action</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/skyraptor/filament-job-action"><img src="https://img.shields.io/packagist/v/skyraptor/filament-job-action.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/skyraptor/filament-job-action"><img src="https://img.shields.io/packagist/php-v/skyraptor/filament-job-action.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/skyraptor/filament-job-action"><img src="https://badge.laravel.cloud/badge/skyraptor/filament-job-action?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/skyraptor/filament-job-action/actions"><img alt="GitHub Workflow Status (master)" src="https://img.shields.io/github/actions/workflow/status/skyraptor/filament-job-action/tests.yml?branch=master&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/skyraptor/filament-job-action"><img src="https://img.shields.io/packagist/dt/skyraptor/filament-job-action.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Filament PHP Action to poll queued workloads

## Installation

You can install the package via Composer:

```bash
composer require skyraptor/filament-job-action
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="filament-job-action"
```

Or, you may publish each resource individually:

### Publishing the Views

```bash
php artisan vendor:publish --tag="filament-job-action-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="filament-job-action-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="filament-job-action-assets"
```

## Usage

Package classes use the `SkyRaptor\FilamentJobAction` namespace.

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Filament Job Action! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Patrick Henninger](https://github.com/skyraptor)
- [All Contributors](../../contributors)

## License

Filament Job Action is open-sourced software licensed under the
[GNU Affero General Public License, version 3 only](COPYING) with the
[Library Integration Exception](COPYING.EXCEPTION).
