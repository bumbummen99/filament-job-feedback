<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Queue\Connectors\SyncConnector;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Workbench\App\Filament\Pages\JobFeedbackDemo;
use Workbench\App\Filament\Pages\ReportJobs;
use Workbench\App\Filament\Resources\Documents\DocumentResource;
use Workbench\App\Jobs\StaleClaimDemoJob;
use Workbench\App\Models\Document;
use Workbench\App\Policies\DocumentPolicy;

class WorkbenchServiceProvider extends PanelProvider
{
    public function boot(): void
    {
        if ($this->app->environment('local', 'testing')) {
            $this->registerSilentCompletionQueue();
        }

        $browserTest = $this->app->environment('local') && config()->boolean('workbench-browser.enabled', false);

        if ($browserTest) {
            config([
                'database.connections.sqlite.busy_timeout' => 5000,
                'database.connections.sqlite.journal_mode' => 'WAL',
                'filament-job-feedback.polling_interval' => '350ms',
                'filament-job-feedback.discover_shared_jobs' => config()->boolean('workbench-browser.discovery'),
                'workbench.job_feedback_pause' => 0,
            ]);

            if (config()->boolean('workbench-browser.recovery')) {
                config(['workbench.job_feedback_pause' => 20, 'filament-job-feedback.stale_grace_seconds' => 0]);
            }
        }

        Gate::define('run-workbench-jobs', fn (?Authenticatable $user): bool => $this->app->environment('local', 'testing')
            && (! $browserTest || in_array($user?->getAuthIdentifier(), [1, 2], true)));
        Gate::policy(Document::class, DocumentPolicy::class);
    }

    private function registerSilentCompletionQueue(): void
    {
        $connection = StaleClaimDemoJob::SilentConnection;
        config(["queue.connections.{$connection}" => ['driver' => $connection]]);

        $this->app->make(QueueManager::class)->addConnector($connection, static fn (): SyncConnector => new class extends SyncConnector
        {
            /** @param array<string, mixed> $config */
            public function connect(array $config): SyncQueue
            {
                return new class extends SyncQueue
                {
                    protected function raiseAfterJobEvent(Job $job, mixed $duration = null): void
                    {
                        // Keep the real processing event, but simulate a lost completion event.
                    }
                };
            }
        });
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('workbench')
            ->path('workbench')
            ->brandName('Filament Job Feedback')
            ->pages([Dashboard::class, JobFeedbackDemo::class, ReportJobs::class])
            ->resources([DocumentResource::class])
            ->widgets([AccountWidget::class, FilamentInfoWidget::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DispatchServingFilamentEvent::class,
            ]);
    }
}
