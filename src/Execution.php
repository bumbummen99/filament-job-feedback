<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback;

final readonly class Execution
{
    /** @param list<array{status: ExecutionStatus, attempt: int}> $history */
    public function __construct(
        public string $id,
        public ?string $identity,
        public ExecutionStatus $status = ExecutionStatus::DispatchPending,
        public int $attempt = 0,
        public array $history = [],
        public ?int $processingStartedAt = null,
        public ?int $processingTimeout = null,
        public ?string $replaces = null,
    ) {}

    public function isStale(?int $at = null): bool
    {
        if ($this->identity === null || $this->status !== ExecutionStatus::Processing || ($this->processingStartedAt ?? null) === null) {
            return false;
        }

        $fallback = config('filament-job-feedback.stale_fallback_timeout_seconds');
        $timeout = $this->processingTimeout ?? (is_int($fallback) ? $fallback : null);

        return $timeout !== null && $timeout > 0
            && ($at ?? now()->getTimestamp()) - $this->processingStartedAt >= $timeout + max(0, config()->integer('filament-job-feedback.stale_grace_seconds', 30));
    }

    public function transition(ExecutionStatus $status, int $attempt, ?int $processingStartedAt = null, ?int $processingTimeout = null): self
    {
        if ($this->status->isTerminal() || $attempt < $this->attempt) {
            return $this;
        }

        $allowed = match ($status) {
            ExecutionStatus::DispatchPending => false,
            ExecutionStatus::DispatchUnknown => $this->status === ExecutionStatus::DispatchPending,
            ExecutionStatus::Queued => $this->isAwaitingDispatch(),
            ExecutionStatus::Processing => $attempt > $this->attempt,
            ExecutionStatus::Retrying, ExecutionStatus::TimedOut, ExecutionStatus::Completed => $this->status === ExecutionStatus::Processing
                && $attempt === $this->attempt,
            ExecutionStatus::Failed => true,
            ExecutionStatus::Discarded => $this->status === ExecutionStatus::DispatchPending && $attempt === 0,
        };

        if (! $allowed) {
            return $this;
        }

        $history = $this->history;

        if ($this->isAwaitingDispatch() && $attempt > 0) {
            $history[] = ['status' => ExecutionStatus::Queued, 'attempt' => 0];
        }

        if ($status !== ExecutionStatus::DispatchUnknown) {
            $history[] = ['status' => $status, 'attempt' => $attempt];
        }

        return new self($this->id, $this->identity, $status, $attempt, $history, $processingStartedAt, $processingTimeout, $this->replaces ?? null);
    }

    private function isAwaitingDispatch(): bool
    {
        return $this->status === ExecutionStatus::DispatchPending || $this->status === ExecutionStatus::DispatchUnknown;
    }
}
