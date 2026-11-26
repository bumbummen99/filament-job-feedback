<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Actions;

use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use LogicException;
use SkyRaptor\FilamentJobFeedback\Actions\Concerns\ConfiguresJobFeedback;

class JobBulkAction extends BulkAction
{
    use ConfiguresJobFeedback;

    /** @return EloquentCollection<array-key, Model>|Collection<array-key, Model>|LazyCollection<array-key, Model> */
    public function getSelectedRecords(): EloquentCollection|Collection|LazyCollection
    {
        if ($this->canAccessSelectedRecords() && $this->isStaleJobReplacementRequest()) {
            $records = $this->getReplacementSelection();
            $this->successfulSelectedRecordsCount = $this->totalSelectedRecordsCount = count($records);

            return new EloquentCollection($records);
        }

        return parent::getSelectedRecords();
    }

    /** @return Builder<Model> */
    public function getSelectedRecordsQuery(): Builder
    {
        if ($this->canAccessSelectedRecords() && $this->isStaleJobReplacementRequest()) {
            return parent::getSelectedRecordsQuery()->getModel()->newQuery()
                ->whereKey((new EloquentCollection($this->getReplacementSelection()))->modelKeys());
        }

        return parent::getSelectedRecordsQuery();
    }

    /** @return list<Model> */
    private function getReplacementSelection(): array
    {
        $livewire = $this->getLivewire();

        return method_exists($livewire, 'getStaleBulkReplacementRecords')
            ? $livewire->getStaleBulkReplacementRecords($this)
            : [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->action(function (): void {
            $livewire = $this->getLivewire();

            if (! $livewire instanceof HasJobFeedback || ! $livewire instanceof HasTable) {
                throw new LogicException('JobBulkAction requires a table host that implements HasJobFeedback and uses InteractsWithJobFeedback.');
            }

            $replacing = $this->isStaleJobReplacementRequest();
            $records = $replacing ? $this->getReplacementSelection() : $this->getIndividuallyAuthorizedSelectedRecords();
            $authorized = 0;
            $ineligible = 0;
            $blocked = 0;
            $total = 0;
            // Keep one slot open until selection is complete, even if every submission settles immediately.
            $remaining = 1;
            $settle = function (bool $accepted) use (&$remaining, &$authorized, &$ineligible, &$blocked, &$total, $replacing): void {
                $blocked += (int) ! $accepted;

                if (--$remaining === 0) {
                    $this->notifySubmissionResult($total, $authorized, $ineligible, $blocked, $replacing);
                }
            };

            foreach ($records as $record) {
                $authorized++;

                if (! $record instanceof Model) {
                    throw new LogicException('JobBulkAction requires Eloquent table records.');
                }

                if (! $this->shouldQueueJob($record)) {
                    $ineligible++;

                    continue;
                }

                $remaining++;
                $livewire->startJobFeedback($this, fn (): ShouldQueue => $this->makeQueuedJob($record), $settle, $record);
            }

            $total = $replacing ? $authorized : $this->getTotalSelectedRecordsCount();
            $settle(true);
        });
    }

    private function notifySubmissionResult(int $total, int $authorized, int $ineligible, int $blocked, bool $replacing): void
    {
        if ($replacing && $total === 0) {
            Notification::make()
                ->title(__('filament-job-feedback::messages.stale_replacement_blocked'))
                ->warning()
                ->send();

            return;
        }

        $denied = $total - $authorized;
        $reasons = [];

        if ($denied > 0) {
            $reasons[] = trans_choice('filament-job-feedback::messages.bulk_records_denied', $denied);
        }

        if ($ineligible > 0) {
            $reasons[] = trans_choice('filament-job-feedback::messages.bulk_records_ineligible', $ineligible);
        }

        if ($blocked > 0) {
            $reasons[] = trans_choice($replacing
                ? 'filament-job-feedback::messages.bulk_replacements_blocked'
                : 'filament-job-feedback::messages.unique_jobs_blocked', $blocked);
        }

        $notification = Notification::make()->title(__('filament-job-feedback::messages.bulk_requests_accepted', [
            'accepted' => $authorized - $ineligible - $blocked,
            'total' => $total,
        ]));

        ($reasons === [] ? $notification->success() : $notification->body(implode(' ', $reasons))->warning())->send();
    }
}
