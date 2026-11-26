<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Tests\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;
use SkyRaptor\FilamentJobFeedback\Actions\HasJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\InteractsWithJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\JobAction;
use SkyRaptor\FilamentJobFeedback\ExecutionStatus;

final class JobActionHost extends Component implements HasActions, HasJobFeedback, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithJobFeedback;
    use InteractsWithSchemas;

    public ?FeedbackRecord $record = null;

    /** @var list<string> */
    public array $sharedEvents = [];

    public function confirmAction(): JobAction
    {
        return JobAction::make('confirm')
            ->requiresConfirmation()
            ->job(new FeedbackJob);
    }

    public function configureAction(): JobAction
    {
        return JobAction::make('configure')
            ->schema([Toggle::make('enabled')->default(true)])
            ->job(new FeedbackJob);
    }

    public function sharedAction(): JobAction
    {
        return JobAction::make('shared')
            ->authorize('follow-feedback')
            ->requiresConfirmation()
            ->job(new DeduplicatedFeedbackJob)
            ->onJobQueued(fn (ExecutionStatus $status) => $this->recordSharedEvent($status))
            ->onJobProcessing(fn (ExecutionStatus $status) => $this->recordSharedEvent($status))
            ->onJobRetrying(fn (ExecutionStatus $status) => $this->recordSharedEvent($status))
            ->onJobCompleted(fn (ExecutionStatus $status) => $this->recordSharedEvent($status));
    }

    public function submitAction(): JobAction
    {
        return JobAction::make('submit')
            ->pollingInterval('750ms')
            ->schema([Toggle::make('enabled')->default(true), Toggle::make('retry')])
            ->queueIf(fn (array $data): bool => ($data['enabled'] ?? false) === true)
            ->job(fn (array $data): FeedbackJob => new FeedbackJob(releaseThenFail: ($data['retry'] ?? false) === true));
    }

    public function recoverableAction(): JobAction
    {
        return JobAction::make('recoverable')
            ->allowStaleJobReplacement()
            ->schema([Toggle::make('enabled')->default(true), Toggle::make('alternate')])
            ->queueIf(fn (array $data): bool => ($data['enabled'] ?? false) === true)
            ->job(fn (array $data): DeduplicatedFeedbackJob => new DeduplicatedFeedbackJob(
                ($data['alternate'] ?? false) ? 'other' : 'original',
            ));
    }

    public function processAction(): JobAction
    {
        return JobTableHost::processRecordWith(JobAction::make('process'))
            ->record($this->record)
            ->authorize('process-feedback-record')
            ->visible(fn (): bool => $this->record !== null);
    }

    private function recordSharedEvent(ExecutionStatus $status): void
    {
        $this->sharedEvents[] = $status->value;
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                {{ $this->confirmAction }}
                {{ $this->configureAction }}
                {{ $this->sharedAction }}
                {{ $this->submitAction }}
                {{ $this->recoverableAction }}
                @if ($this->record !== null)
                    {{ $this->processAction }}
                @endif

                <x-filament-actions::modals />
                @include('filament-job-feedback::status')
            </div>
            BLADE;
    }
}
