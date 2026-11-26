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
use UnitEnum;
use Workbench\App\Jobs\DemoJob;

final class ReportJobs extends Page implements HasJobFeedback
{
    use InteractsWithJobFeedback;

    protected string $view = 'report-jobs';

    protected static ?string $title = 'Report jobs';

    protected static string|UnitEnum|null $navigationGroup = 'Job Feedback Examples';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    public static function canAccess(): bool
    {
        return app()->environment('local', 'testing');
    }

    public function reportAction(): JobAction
    {
        return JobAction::make('report')
            ->pollingInterval('750ms')
            ->label('Generate report')
            ->icon(Heroicon::DocumentChartBar)
            ->authorize('run-workbench-jobs')
            ->schema([
                Toggle::make('allowReports')->label('Allow report dispatch')->default(true),
                Toggle::make('retryThenFail')->label('Demonstrate retry and failure'),
            ])
            ->job(fn (array $data): DemoJob => new DemoJob(
                config()->integer('workbench.job_feedback_pause', 6),
                retryThenFail: ($data['retryThenFail'] ?? false) === true,
            ))
            ->queueIf(fn (array $data): bool => ($data['allowReports'] ?? false) === true)
            ->onJobCompleted(fn () => Notification::make()->title('Report generated')->success()->send())
            ->onJobFailed(fn () => Notification::make()->title('Demo report failed')->danger()->send());
    }
}
