<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback;

use Closure;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Queue\InteractsWithUniqueJobs;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\InvalidPayloadException;
use Illuminate\Queue\Queue as LaravelQueue;
use Illuminate\Support\Facades\Queue;
use LogicException;
use ReflectionMethod;
use SkyRaptor\FilamentJobFeedback\Contracts\ExecutionReconciler;
use SkyRaptor\FilamentJobFeedback\Contracts\ShouldBeDeduplicated;
use Throwable;

final class FilamentJobFeedback
{
    use InteractsWithUniqueJobs;

    /** @var array{Execution, UniqueLock|null, ShouldBeUnique|null}|null */
    private ?array $dispatching = null;

    /** @var array<string, array{DatabaseTransactionRecord, Closure(): void}> */
    private array $rollbackCallbacks = [];

    public function __construct(
        private readonly CacheExecutionStore $executions,
        private readonly Dispatcher $bus,
    ) {}

    /** @internal */
    public static function deduplicationKey(ShouldQueue $job): ?string
    {
        if (! $job instanceof ShouldBeDeduplicated) {
            return null;
        }

        if (! method_exists($job, 'deduplicationId')) {
            return serialize([$job::class, null]);
        }

        if (! (new ReflectionMethod($job, 'deduplicationId'))->isPublic() || ! is_string($id = $job->deduplicationId())) {
            throw new LogicException('The job deduplicationId() must be public and return a string.');
        }

        return serialize([$job::class, $id]);
    }

    /**
     * @internal
     *
     * @param  Closure(?string): void  $settled
     */
    public function submit(ShouldQueue $command, Closure $settled, ?string $replaceStale = null, ?int $expectedAttempt = null): void
    {
        $submit = fn () => $settled($this->start(static fn (): ShouldQueue => $command, $replaceStale, $expectedAttempt));
        $transactions = $this->transactions();

        if ($transactions !== null && $transactions->callbackApplicableTransactions()->isNotEmpty()
            && $this->dispatchesAfterCommit($command)) {
            $transactions->addCallback($submit);

            return;
        }

        $submit();
    }

    /**
     * @param  Closure(): ShouldQueue  $job
     * @return string|null The tracked execution ID, or null if dispatch was blocked or the replacement is no longer valid.
     */
    public function start(Closure $job, ?string $replaceStale = null, ?int $expectedAttempt = null): ?string
    {
        $command = $job();
        $key = self::deduplicationKey($command);

        if ($key !== null && app()->bound(ExecutionReconciler::class) && ($active = $this->executions->active($key)) !== null) {
            $state = $this->reconcile($active);

            if ($active->id === $replaceStale && $state !== ExecutionClaimState::Unknown) {
                return null;
            }
        }

        $uniqueLock = null;
        $uniqueJob = null;

        try {
            $claim = $this->executions->claim($key, function () use ($command, &$uniqueLock, &$uniqueJob): ?ShouldQueue {
                if ($command instanceof ShouldBeUnique) {
                    $uniqueLock = new UniqueLock(app(Repository::class));

                    if (! $uniqueLock->acquire($command)) {
                        return null;
                    }

                    $uniqueJob = $command;
                    $this->addUniqueJobInformationToContext($command);
                }

                return $command;
            }, $replaceStale, $expectedAttempt);
        } catch (Throwable $exception) {
            if ($uniqueJob !== null) {
                try {
                    $uniqueLock?->release($uniqueJob);
                } finally {
                    $this->removeUniqueJobInformationFromContext($uniqueJob);
                }
            }

            throw $exception;
        }

        if ($claim === null) {
            // Laravel's native unique lock blocked a new dispatch.
            return null;
        }

        [$execution, $claimedCommand] = $claim;

        if ($claimedCommand !== null) {
            $this->dispatchClaimedJob($execution, $claimedCommand, $uniqueLock, $uniqueJob);
        }

        return $execution->id;
    }

    private function dispatchClaimedJob(Execution $execution, ShouldQueue $command, ?UniqueLock $uniqueLock, ?ShouldBeUnique $uniqueJob): void
    {
        $previous = $this->dispatching;
        $this->dispatching = [$execution, $uniqueLock, $uniqueJob];

        try {
            $this->bus->dispatch($command);
        } catch (Throwable $exception) {
            if ($this->payloadWasRejected($exception, $execution->id)
                && $this->executions->discardRejectedPayload($execution->id)) {
                unset($this->rollbackCallbacks[$execution->id]);

                if ($uniqueJob !== null) {
                    $uniqueLock?->release($uniqueJob);
                }
            } else {
                $this->executions->record($execution->id, ExecutionStatus::DispatchUnknown);
            }

            throw $exception;
        } finally {
            $this->dispatching = $previous;

            if ($uniqueJob !== null) {
                $this->removeUniqueJobInformationFromContext($uniqueJob);
            }
        }
    }

    private function payloadWasRejected(Throwable $exception, string $id): bool
    {
        if (! $exception instanceof InvalidPayloadException || ! is_array($exception->value)
            || ($exception->value[LaravelQueueMonitor::PayloadKey] ?? null) !== $id) {
            return false;
        }

        // Custom drivers and event listeners can also throw this exception after sending.
        $origin = $exception->getTrace()[0] ?? [];

        return ($origin['class'] ?? null) === LaravelQueue::class && $origin['function'] === 'createPayload';
    }

    /** @internal */
    public function clearRolledBackDispatches(TransactionRolledBack $event): void
    {
        $transactions = $this->transactions();

        if ($transactions === null || $this->rollbackCallbacks === []) {
            return;
        }

        // Laravel keeps committed savepoints until the outer transaction settles.
        $remaining = $transactions->getPendingTransactions()->concat($transactions->getCommittedTransactions());

        foreach ($this->rollbackCallbacks as $id => [$transaction, $discard]) {
            if ($transaction->connection === $event->connectionName && ! $remaining->containsStrict($transaction)) {
                unset($this->rollbackCallbacks[$id]);
                $discard();
            }
        }
    }

    private function dispatchesAfterCommit(ShouldQueue $command): bool
    {
        if (isset($command->afterCommit)) {
            return (bool) $command->afterCommit;
        }

        return $command instanceof ShouldQueueAfterCommit
            || (bool) config('queue.connections.'.$this->queueConnection($command).'.after_commit', false);
    }

    private function databaseQueueTransaction(?string $connection): ?DatabaseTransactionRecord
    {
        $pending = $this->transactions()?->callbackApplicableTransactions();

        if ($pending === null || $pending->isEmpty()) {
            return null;
        }

        $queue = Queue::connection($connection);

        if (! $queue instanceof DatabaseQueue) {
            return null;
        }

        return $pending->last(fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $queue->getDatabase()->getName());
    }

    private function queueConnection(ShouldQueue $command): string
    {
        $connection = $command->connection ?? null;

        if ($connection === null && app()->bound('queue.routes')) {
            $connection = app('queue.routes')->getConnection($command);
        }

        return is_string($connection) ? $connection : Queue::getDefaultDriver();
    }

    private function transactions(): ?DatabaseTransactionsManager
    {
        return app()->bound('db.transactions') ? app('db.transactions') : null;
    }

    /** @internal Called once when Laravel builds the dispatched job's payload. */
    public function takeDispatchingExecution(?string $connection): ?string
    {
        if ($this->dispatching === null) {
            return null;
        }

        [$execution, $uniqueLock, $uniqueJob] = $this->dispatching;
        $this->dispatching = null;

        if (($transaction = $this->databaseQueueTransaction($connection)) !== null) {
            $this->rollbackCallbacks[$execution->id] = [$transaction, function () use ($execution, $uniqueLock, $uniqueJob): void {
                if ($uniqueJob !== null) {
                    $uniqueLock?->release($uniqueJob);
                }

                $this->executions->discardRolledBack($execution);
            }];
            $transaction->addCallback(function () use ($execution): void {
                unset($this->rollbackCallbacks[$execution->id]);
            });
        }

        return $execution->id;
    }

    public function active(string $key): ?Execution
    {
        $active = $this->executions->active($key);

        return $active !== null && $this->reconcile($active) === ExecutionClaimState::Terminal
            ? $this->executions->active($key)
            : $active;
    }

    /** @internal */
    public function reconcile(Execution $execution): ExecutionClaimState
    {
        if (! app()->bound(ExecutionReconciler::class) || $execution->status->isTerminal() || ! $this->hasClaim($execution)) {
            return ExecutionClaimState::Unknown;
        }

        $state = app(ExecutionReconciler::class)->state($execution);

        if ($state === ExecutionClaimState::Terminal) {
            $this->executions->releaseReconciled($execution);
        }

        return $state;
    }

    /** @internal */
    public function hasClaim(Execution $execution): bool
    {
        return $this->executions->hasClaim($execution);
    }

    /** @internal */
    public function replacementFor(Execution $execution): ?Execution
    {
        return $this->executions->replacementFor($execution);
    }

    public function find(string $id): ?Execution
    {
        return $this->executions->find($id);
    }
}
