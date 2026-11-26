<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Actions;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;

interface HasJobFeedback
{
    /**
     * @internal
     *
     * @param  Closure(): ShouldQueue  $job
     * @param  Closure(bool): void  $settled
     */
    public function startJobFeedback(JobAction|JobBulkAction $action, Closure $job, Closure $settled, ?Model $record = null): void;

    /** @internal */
    public function isJobFeedbackPending(JobAction|JobBulkAction $action, bool $includeUndelivered = true): bool;

    /** @internal */
    public function rememberJobFeedbackAction(JobAction $action): void;

    /** @internal */
    public function getJobFeedbackPollingInterval(): ?string;
}
