<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Contracts;

use SkyRaptor\FilamentJobFeedback\Execution;
use SkyRaptor\FilamentJobFeedback\ExecutionClaimState;

interface ExecutionReconciler
{
    /** Report queue evidence for this exact execution ID, not its logical deduplication identity. */
    public function state(Execution $execution): ExecutionClaimState;
}
