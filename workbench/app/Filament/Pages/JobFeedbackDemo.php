<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Pages;

use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use SkyRaptor\FilamentJobFeedback\Actions\HasJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\InteractsWithJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\JobAction;
use SkyRaptor\FilamentJobFeedback\ExecutionStatus;
use UnitEnum;
use Workbench\App\Jobs\RebuildSearchIndex;
use Workbench\App\Jobs\StaleClaimDemoJob;

final class JobFeedbackDemo extends Page implements HasJobFeedback
{
    use InteractsWithJobFeedback;

    protected string $view = 'job-feedback-demo';

    protected static ?string $title = 'Global maintenance';

    protected static ?string $slug = 'global-maintenance';

    protected static string|UnitEnum|null $navigationGroup = 'Job Feedback Examples';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    /** @var list<string> */
    public array $sharedEvents = [];

    public static function canAccess(): bool
    {
        return app()->environment('local', 'testing');
    }

    public function rebuildAction(): JobAction
    {
        $job = new RebuildSearchIndex($this->seconds());

        if (config()->boolean('workbench-browser.recovery', false)) {
            $job->timeout = 2;
        }

        return JobAction::make('rebuild')
            ->label('Rebuild search index')
            ->icon(Heroicon::ArrowPath)
            ->authorize('run-workbench-jobs')
            ->requiresConfirmation()
            ->allowStaleJobReplacement()
            ->job($job)
            ->onJobQueued(fn (ExecutionStatus $status) => $this->recordSharedEvent($status))
            ->onJobProcessing(fn (ExecutionStatus $status) => $this->recordSharedEvent($status))
            ->onJobCompleted(function (ExecutionStatus $status): void {
                $this->recordSharedEvent($status);

                Notification::make()->title('Search index rebuilt')->success()->send();
            });
    }

    public function simulateStaleClaimAction(): JobAction
    {
        return JobAction::make('simulateStaleClaim')
            ->label('Simulate a lost update')
            ->icon(Heroicon::ExclamationTriangle)
            ->color('warning')
            ->authorize('run-workbench-jobs')
            ->allowStaleJobReplacement()
            ->schema([
                Toggle::make('loseCompletion')
                    ->label('Drop the completion update')
                    ->helperText('Turn this off when starting a replacement to let the new run complete normally.')
                    ->default(true),
            ])
            ->job(fn (array $data): StaleClaimDemoJob => (new StaleClaimDemoJob)
                ->onConnection(($data['loseCompletion'] ?? true) ? StaleClaimDemoJob::SilentConnection : 'sync'), discover: true);
    }

    public function getStaleClaimDemoWaitSeconds(): int
    {
        return StaleClaimDemoJob::Timeout + max(0, config()->integer('filament-job-feedback.stale_grace_seconds', 30));
    }

    private function recordSharedEvent(ExecutionStatus $status): void
    {
        $this->sharedEvents[] = $status->value;
    }

    private function seconds(): int
    {
        return config()->integer('workbench.job_feedback_pause', 6);
    }
}
