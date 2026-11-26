<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback;

enum ExecutionStatus: string
{
    case DispatchPending = 'dispatch_pending';
    case DispatchUnknown = 'dispatch_unknown';
    case Queued = 'queued';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case TimedOut = 'timed_out';
    case Completed = 'completed';
    case Failed = 'failed';
    case Discarded = 'discarded';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed || $this === self::Discarded;
    }
}
