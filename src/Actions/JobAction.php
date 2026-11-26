<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use LogicException;
use SkyRaptor\FilamentJobFeedback\Actions\Concerns\ConfiguresJobFeedback;

class JobAction extends Action
{
    use ConfiguresJobFeedback;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action(function (): void {
            if (! $this->shouldQueueJob()) {
                return;
            }

            $livewire = $this->getLivewire();

            if (! $livewire instanceof HasJobFeedback) {
                throw new LogicException('The Livewire host must implement HasJobFeedback and use InteractsWithJobFeedback.');
            }

            $replacement = $this->getStaleJobReplacement();

            if ($this->isStaleJobReplacementRequest() && $replacement === null) {
                return;
            }

            $livewire->startJobFeedback($this, fn (): ShouldQueue => $this->makeQueuedJob(), static function (bool $accepted) use ($replacement): void {
                if (! $accepted) {
                    Notification::make()
                        ->title(__($replacement === null
                            ? 'filament-job-feedback::messages.unique_job_blocked'
                            : 'filament-job-feedback::messages.stale_replacement_blocked'))
                        ->warning()
                        ->send();
                }
            });
        });
    }
}
