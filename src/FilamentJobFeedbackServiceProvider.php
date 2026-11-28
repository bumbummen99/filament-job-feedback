<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Queue;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Livewire\Livewire;
use LogicException;
use SkyRaptor\FilamentJobFeedback\Actions\HasJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\HasSharedOperationFeedback;
use SkyRaptor\FilamentJobFeedback\Contracts\ExecutionMonitor;

class FilamentJobFeedbackServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filament-job-feedback.php', 'filament-job-feedback');

        $this->app->singleton(CacheExecutionStore::class, function (): CacheExecutionStore {
            $store = config('filament-job-feedback.cache_store');

            return new CacheExecutionStore($this->app->make(CacheManager::class)->store(is_string($store) ? $store : null));
        });
        $this->app->alias(CacheExecutionStore::class, ExecutionMonitor::class);
        $this->app->singleton(FilamentJobFeedback::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::createPayloadUsing(static function (?string $connection, ?string $queue, array $payload): array {
            $feedback = app(FilamentJobFeedback::class);
            $id = $feedback->takeDispatchingExecution($connection);

            if ($id !== null) {
                return [LaravelQueueMonitor::PayloadKey => $id];
            }

            if (! config()->boolean('filament-job-feedback.track_external_jobs')) {
                return [];
            }

            $job = $payload['data']['command'] ?? null;
            $key = $job instanceof ShouldQueue ? FilamentJobFeedback::deduplicationKey($job) : null;

            return [LaravelQueueMonitor::ExternalPayloadKey => $key === null ? true : hash('sha256', $key)];
        });

        $events = $this->app->make(Dispatcher::class);
        $this->app->make(LaravelQueueMonitor::class)->subscribe($events);
        $events->listen(TransactionRolledBack::class, static function (TransactionRolledBack $event): void {
            app(FilamentJobFeedback::class)->clearRolledBackDispatches($event);
        });

        Livewire::listen('render', static function (Component $component): ?Closure {
            if (! $component instanceof HasJobFeedback) {
                return null;
            }

            return static function (string $html, Closure $replaceHtml) use ($component): void {
                $interval = $component->getJobFeedbackPollingInterval();

                // Key the node by interval so Livewire initializes a new directive when the cadence changes.
                $poller = $interval === null
                    ? ''
                    : '<template wire:key="filament-job-feedback-poller-'.$interval.'" wire:poll.'.$interval.'="pollJobFeedback"></template>';

                // Isolate poller changes from the host's stateful DOM, including Filament's table selection.
                $poller = '<!--[if BLOCK]><![endif]-->'.$poller.'<!--[if ENDBLOCK]><![endif]-->';
                $html = preg_replace(
                    '~\A(?:\s+|<!--.*?-->)*\K<[a-zA-Z][\w:-]*(?:"[^"]*"|\'[^\']*\'|[^\'">])*+>~s',
                    '$0'.$poller,
                    $html,
                    1,
                    $count,
                );

                if ($html === null || $count !== 1) {
                    throw new LogicException('The job feedback poller requires a Livewire root element.');
                }

                $replaceHtml($html);
            };
        });

        Livewire::listen('render', static function (Component $component): ?Closure {
            if (! $component instanceof HasSharedOperationFeedback) {
                return null;
            }

            return static function (string $html, Closure $replaceHtml) use ($component): void {
                $poller = $component->hasSharedOperationSubscriptions()
                    ? '<template wire:key="filament-shared-operation-poller" wire:poll.2s="pollSharedOperations"></template>'
                    : '';
                $html = preg_replace(
                    '~\A(?:\s+|<!--.*?-->)*\K<[a-zA-Z][\w:-]*(?:"[^"]*"|\'[^\']*\'|[^\'">])*+>~s',
                    '$0<!--[if BLOCK]><![endif]-->'.$poller.'<!--[if ENDBLOCK]><![endif]-->',
                    $html,
                    1,
                    $count,
                );

                if ($html === null || $count !== 1) {
                    throw new LogicException('The shared operation feedback poller requires a Livewire root element.');
                }
                $replaceHtml($html);
            };
        });

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-job-feedback');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'filament-job-feedback');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/filament-job-feedback.php' => config_path('filament-job-feedback.php'),
        ], ['filament-job-feedback', 'filament-job-feedback-config']);

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
