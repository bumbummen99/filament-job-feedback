<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use RuntimeException;
use SkyRaptor\FilamentJobFeedback\Contracts\ExecutionMonitor;

final readonly class CacheExecutionStore implements ExecutionMonitor
{
    public function __construct(private Repository $cache) {}

    /**
     * @param  Closure(): (ShouldQueue|null)  $prepare
     * @return array{Execution, ShouldQueue}|array{Execution, null}|null A new claim, an existing run to join, or a blocked dispatch.
     */
    public function claim(?string $key, Closure $prepare, ?string $replaceStale = null, ?int $expectedAttempt = null): ?array
    {
        $id = (string) Str::uuid();
        $identity = $key === null ? null : hash('sha256', $key);
        $execution = new Execution($id, $identity, replaces: $replaceStale);

        return $this->locked($identity ?? $id, function () use ($identity, $execution, $prepare, $replaceStale, $expectedAttempt): ?array {
            $active = $identity === null ? null : $this->readActive($identity);

            if ($replaceStale === null && $active !== null) {
                return [$active, null];
            }

            if ($replaceStale !== null) {
                if ($active === null) {
                    return null;
                }

                if ($active->id !== $replaceStale) {
                    return ($active->replaces ?? null) === $replaceStale ? [$active, null] : null;
                }

                if ($active->attempt !== $expectedAttempt || ! $active->isStale()
                    || $this->readActive($identity, except: $replaceStale) !== null) {
                    return null;
                }
            }

            // Do not publish a claim another request can join until Laravel permits dispatch.
            $command = $prepare();

            if ($command === null) {
                return null;
            }

            $this->persist($execution);

            if ($replaceStale !== null && ! $this->cache->put($this->replacementKey($replaceStale), $execution->id, now()->addDay())) {
                throw new RuntimeException('Unable to link the stale job feedback execution to its replacement.');
            }

            $this->index($execution, $replaceStale);

            return [$execution, $command];
        });
    }

    public function observe(string $id, ?string $identity): void
    {
        if ($this->find($id) !== null) {
            return;
        }

        $this->locked($identity ?? $id, function () use ($id, $identity): void {
            if ($this->find($id) !== null) {
                return;
            }

            $execution = new Execution($id, $identity);
            $this->persist($execution);
            $this->index($execution);
        });
    }

    public function active(string $key): ?Execution
    {
        $identity = hash('sha256', $key);

        return $this->locked($identity, fn (): ?Execution => $this->readActive($identity));
    }

    private function readActive(string $identity, ?string $except = null): ?Execution
    {
        foreach ($this->activeIds($identity) as $id) {
            if ($id === $except) {
                continue;
            }

            $execution = $this->find($id) ?? throw new RuntimeException('The active job feedback execution is missing from the cache.');

            if (! $execution->status->isTerminal()) {
                return $execution;
            }
        }

        return null;
    }

    public function hasClaim(Execution $execution): bool
    {
        return $execution->identity !== null && in_array($execution->id, $this->activeIds($execution->identity), true);
    }

    public function replacementFor(Execution $execution): ?Execution
    {
        if ($execution->identity === null || $this->hasClaim($execution)) {
            return null;
        }

        $id = $this->cache->get($this->replacementKey($execution->id));
        $replacement = is_string($id) ? $this->find($id) : null;

        return $replacement !== null && $replacement->identity === $execution->identity
            && ($replacement->replaces ?? null) === $execution->id
            && $replacement->status !== ExecutionStatus::Discarded
            && ($replacement->status !== ExecutionStatus::DispatchPending || $this->hasClaim($replacement))
                ? $replacement
                : null;
    }

    public function releaseReconciled(Execution $observed): void
    {
        if ($observed->identity === null) {
            return;
        }

        $this->locked($observed->identity, function () use ($observed): void {
            $current = $this->find($observed->id);

            if ($current !== null && $current->status === $observed->status && $current->attempt === $observed->attempt && $this->hasClaim($current)) {
                $this->release($current);
            }
        });
    }

    public function find(string $id): ?Execution
    {
        $value = $this->cache->get($this->executionKey($id));

        return $value instanceof Execution ? $value : null;
    }

    /** @internal Only for an enqueue known to have been rolled back by the database. */
    public function discardRolledBack(Execution $execution): void
    {
        $this->locked($execution->identity ?? $execution->id, function () use ($execution): void {
            $this->release($execution, restoreReplaced: true);

            if (! $this->cache->forget($this->executionKey($execution->id)) && $this->find($execution->id) !== null) {
                throw new RuntimeException('Unable to discard the rolled-back job feedback execution.');
            }
        });
    }

    public function record(string $id, ExecutionStatus $status, int $attempt = 0): void
    {
        $this->transition($id, $status, $attempt);
    }

    public function recordProcessing(string $id, int $attempt, ?int $timeout): void
    {
        $this->transition($id, ExecutionStatus::Processing, $attempt, now()->getTimestamp(), $timeout);
    }

    /** @internal Only for a payload rejected before enqueue. */
    public function discardRejectedPayload(string $id): bool
    {
        return $this->transition($id, ExecutionStatus::Discarded, 0);
    }

    private function transition(string $id, ExecutionStatus $status, int $attempt, ?int $processingStartedAt = null, ?int $processingTimeout = null): bool
    {
        $execution = $this->find($id);

        if ($execution === null) {
            return false;
        }

        return $this->locked($execution->identity ?? $execution->id, function () use ($execution, $status, $attempt, $processingStartedAt, $processingTimeout): bool {
            $current = $this->find($execution->id);

            if ($current === null) {
                return false;
            }

            $next = $current->transition($status, $attempt, $processingStartedAt, $processingTimeout);

            if ($next === $current) {
                return false;
            }

            $this->persist($next);

            if ($next->status->isTerminal()) {
                $this->release($next, restoreReplaced: $status === ExecutionStatus::Discarded);
            }

            return true;
        });
    }

    private function persist(Execution $execution): void
    {
        $key = $this->executionKey($execution->id);
        $saved = $execution->status->isTerminal()
            ? $this->cache->put($key, $execution, now()->addDay())
            : $this->cache->forever($key, $execution);

        if (! $saved) {
            throw new RuntimeException('Unable to save the job feedback execution.');
        }
    }

    private function index(Execution $execution, ?string $replace = null): void
    {
        if ($execution->identity === null) {
            return;
        }

        $ids = $this->activeIds($execution->identity);

        if ($replace === null) {
            $ids[] = $execution->id;
        } else {
            $ids = array_map(fn (string $id): string => $id === $replace ? $execution->id : $id, $ids);
        }

        if (! $this->cache->forever($this->activeKey($execution->identity), $ids)) {
            throw new RuntimeException('Unable to reserve the active job feedback key.');
        }
    }

    private function release(Execution $execution, bool $restoreReplaced = false): void
    {
        if ($execution->identity === null) {
            return;
        }

        $key = $this->activeKey($execution->identity);
        $ids = $this->activeIds($execution->identity);

        if (! in_array($execution->id, $ids, true)) {
            return;
        }

        $remaining = array_values(array_diff($ids, [$execution->id]));

        if ($restoreReplaced && ($execution->replaces ?? null) !== null
            && ($old = $this->find($execution->replaces)) !== null && ! $old->status->isTerminal()) {
            array_unshift($remaining, $old->id);
        }

        if (! ($remaining === [] ? $this->cache->forget($key) : $this->cache->forever($key, $remaining))) {
            throw new RuntimeException('Unable to release the active job feedback key.');
        }
    }

    /** @return list<string> */
    private function activeIds(string $identity): array
    {
        $ids = $this->cache->get($this->activeKey($identity));

        if (is_string($ids)) {
            return [$ids];
        }

        return is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
    }

    /** @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(string $identity, Closure $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Job feedback requires a cache store with atomic lock support.');
        }

        return $store->lock("filament-job-feedback:lock:{$identity}", 30)->block(5, $callback);
    }

    private function activeKey(string $identity): string
    {
        return "filament-job-feedback:active:{$identity}";
    }

    private function executionKey(string $id): string
    {
        return "filament-job-feedback:execution:{$id}";
    }

    private function replacementKey(string $id): string
    {
        return "filament-job-feedback:replacement:{$id}";
    }
}
