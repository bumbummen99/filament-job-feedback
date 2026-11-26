<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback;

use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobTimedOut;
use SkyRaptor\FilamentJobFeedback\Contracts\ExecutionMonitor;

final readonly class LaravelQueueMonitor
{
    public const string PayloadKey = 'filament-job-feedback.execution';

    public const string ExternalPayloadKey = 'filament-job-feedback.external';

    public function __construct(
        private ExecutionMonitor $monitor,
        private CacheExecutionStore $executions,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobQueued::class, function (JobQueued $event): void {
            $id = $this->executionId($event->payload());

            if (is_string($id)) {
                $this->monitor->record($id, ExecutionStatus::Queued);
            }
        });

        $events->listen(
            [JobProcessing::class, JobProcessed::class, JobFailed::class, JobAttempted::class, JobTimedOut::class],
            function (JobProcessing|JobProcessed|JobFailed|JobAttempted|JobTimedOut $event): void {
                $job = $event->job;
                $id = $this->executionId($job->payload());

                if (! is_string($id)) {
                    return;
                }

                $status = match (true) {
                    $event instanceof JobProcessing => ExecutionStatus::Processing,
                    $event instanceof JobFailed => ExecutionStatus::Failed,
                    $event instanceof JobTimedOut && ! $job->hasFailed() => ExecutionStatus::TimedOut,
                    $event instanceof JobAttempted && $job->isReleased() && ! $job->hasFailed() => ExecutionStatus::Retrying,
                    $event instanceof JobProcessed && ! $job->isReleased() && ! $job->hasFailed() => ExecutionStatus::Completed,
                    default => null,
                };

                if ($status !== null) {
                    if ($status === ExecutionStatus::Processing && $this->monitor instanceof CacheExecutionStore) {
                        $this->monitor->recordProcessing($id, $job->attempts(), $job->timeout());
                    } else {
                        $this->monitor->record($id, $status, $job->attempts());
                    }
                }
            },
        );
    }

    /** @param array<string, mixed> $payload */
    private function executionId(array $payload): ?string
    {
        $id = $payload[self::PayloadKey] ?? null;

        if (is_string($id)) {
            return $id;
        }

        $id = $payload['uuid'] ?? null;
        $external = $payload[self::ExternalPayloadKey] ?? null;

        if (! is_string($id) || ($external !== true && ! is_string($external))) {
            return null;
        }

        // A fast worker can see the payload before the producer emits JobQueued.
        $this->executions->observe($id, is_string($external) ? $external : null);

        return $id;
    }
}
