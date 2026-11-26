<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use LogicException;
use SkyRaptor\FilamentJobFeedback\Execution;
use SkyRaptor\FilamentJobFeedback\FilamentJobFeedback;

/** @phpstan-type JobFeedbackSubscription array{id: string, action: string, cursor: int, owner: string|null, record: string|null, row: bool, identity?: string|null, discovered?: bool, followsReplacement?: bool, subscribedAt?: int} */
trait InteractsWithJobFeedback
{
    /** @var array<string, JobFeedbackSubscription> */
    #[Locked]
    public array $jobFeedbackSubscriptions = [];

    /** @var array<string, int> */
    #[Locked]
    public array $jobFeedbackNotifiedStaleAttempts = [];

    /** @var array<string, true> */
    #[Locked]
    public array $jobFeedbackNotifiedStaleBulkActions = [];

    /** @var array<string, array{action: string, record: string|null}> */
    #[Locked]
    public array $jobFeedbackDiscoveryActions = [];

    /** @var array{token: string, subscriptions: array<string, int>}|null */
    #[Locked]
    public ?array $jobFeedbackReplacement = null;

    abstract public function getAction(string|array $actions, bool $isMounting = true): ?Action;

    public function renderingInteractsWithJobFeedback(): void
    {
        $this->jobFeedbackDiscoveryActions = [];
    }

    public function rememberJobFeedbackAction(JobAction $action): void
    {
        if ($action->getLivewire() !== $this || ! $action->discoversJobIdentity() || $action->isHidden() || ! $action->isAuthorized()) {
            return;
        }

        $name = $action->getName();
        $recordKey = null;

        if ($action->getTable() !== null) {
            $record = $action->getRecord();

            if (! $this instanceof HasTable || ! $record instanceof Model) {
                return;
            }

            $recordKey = $this->getTableRecordKey($record);
        }

        $this->jobFeedbackDiscoveryActions[hash('sha256', serialize([$name, $recordKey]))] = ['action' => $name, 'record' => $recordKey];
    }

    /**
     * @internal Called by the job actions only, never with browser-supplied arguments.
     *
     * @param  Closure(): ShouldQueue  $job
     * @param  Closure(bool): void  $settled
     */
    public function startJobFeedback(JobAction|JobBulkAction $action, Closure $job, Closure $settled, ?Model $record = null): void
    {
        $name = $action->getName();
        $recordKey = null;

        $row = $action instanceof JobAction && $action->getTable() !== null;

        if ($row) {
            $tableRecord = $action->getRecord();
            $record = $tableRecord instanceof Model ? $tableRecord : null;
        }

        if ($row || $action instanceof JobBulkAction) {
            if (! $this instanceof HasTable || $record === null) {
                throw new LogicException('An Eloquent table record is required to track this job.');
            }

            $recordKey = $this->getTableRecordKey($record);
        }

        $resolved = $this->resolveJobFeedbackAction($name, $action instanceof JobBulkAction, $row ? $record : null);

        if ($resolved === null) {
            throw new LogicException('Register the named job action on its Livewire host.');
        }

        $command = $job();
        $key = FilamentJobFeedback::deduplicationKey($command);

        if ($resolved->discoversJobIdentity() && $key !== $resolved->getDeduplicationKey($record)) {
            throw new LogicException('A discoverable job must have the same identity before and after submission.');
        }

        $token = $action->getArguments()['filamentJobFeedbackReplacement'] ?? null;
        $replacement = is_string($token) ? $this->resolveStaleJobFeedbackReplacement($action, $token, $recordKey) : null;

        if ($token !== null && $replacement === null) {
            $settled(false);

            return;
        }

        if ($replacement !== null && $action instanceof JobAction) {
            $this->jobFeedbackReplacement = null;
        }

        app(FilamentJobFeedback::class)->submit($command, function (?string $id) use ($name, $recordKey, $row, $key, $settled): void {
            if ($id !== null) {
                $this->subscribeToJobFeedback($id, $name, $recordKey, $row, $key === null ? null : hash('sha256', $key));
            }

            $settled($id !== null);
        }, $replacement['execution'] ?? null, $replacement['attempt'] ?? null);
    }

    public function pollJobFeedback(): void
    {
        foreach ($this->resolveJobFeedbackDiscoveryActions() as $action) {
            $this->isJobFeedbackPending($action);
        }

        $feedback = app(FilamentJobFeedback::class);
        foreach ($this->jobFeedbackSubscriptions as $subscription) {
            $execution = $feedback->find($subscription['id']);

            if ($execution === null || ($replacement = $feedback->replacementFor($execution)) === null
                || $this->resolveValidJobFeedbackSubscription($subscription) === null) {
                continue;
            }

            do {
                $this->subscribeToJobFeedback(
                    $replacement->id,
                    $subscription['action'],
                    $subscription['record'],
                    $subscription['row'],
                    $replacement->identity,
                    $subscription['discovered'] ?? false,
                    followsReplacement: true,
                );
            } while (($replacement = $feedback->replacementFor($replacement)) !== null);
        }

        $deliveries = [];
        $bulkRecords = [];
        $submittedRecords = [];
        $reconciled = [];

        foreach ($this->jobFeedbackSubscriptions as $subscriptionKey => $subscription) {
            $resolved = $this->resolveValidJobFeedbackSubscription($subscription);

            if ($resolved === null) {
                unset($this->jobFeedbackSubscriptions[$subscriptionKey]);

                continue;
            }

            ['execution' => $execution, 'action' => $action, 'record' => $record] = $resolved;

            if (! isset($reconciled[$execution->id])) {
                app(FilamentJobFeedback::class)->reconcile($execution);
                $reconciled[$execution->id] = true;
            }

            $deliveries[$subscriptionKey] = [$subscription, $execution, $action, $record];

            if ($action instanceof JobBulkAction && $subscription['record'] !== null) {
                $bulkRecords[$execution->id][$subscription['record']] = true;
            }

            if ($subscription['record'] !== null && ! ($subscription['discovered'] ?? false)
                && ! ($subscription['followsReplacement'] ?? false)) {
                $submittedRecords[$execution->id][$subscription['record']] = true;
            }
        }

        $this->jobFeedbackNotifiedStaleAttempts = array_intersect_key($this->jobFeedbackNotifiedStaleAttempts, $reconciled);
        $staleBulkActions = [];
        $bulkWarnings = [];

        foreach ($deliveries as $subscriptionKey => [$subscription, $execution, $action, $record]) {
            // The submitted bulk action owns callbacks for automatically following rows.
            $coveredByBulk = $subscription['row'] && ($subscription['discovered'] ?? false)
                && $subscription['record'] !== null && isset($bulkRecords[$execution->id][$subscription['record']]);

            // Keep other triggers in sync, but let the action that submitted the replacement deliver its callbacks.
            $followingSubmittedReplacement = ($subscription['followsReplacement'] ?? false)
                && $subscription['record'] !== null && isset($submittedRecords[$execution->id][$subscription['record']]);

            if ($this->isStaleJobFeedback($execution) && $action->canReplaceStaleJob($record)) {
                $shouldNotify = $action->canShowStaleJobFeedback()
                    && isset($subscription['subscribedAt']) && ! $execution->isStale($subscription['subscribedAt']);

                if ($action instanceof JobBulkAction) {
                    $name = $action->getName();
                    $staleBulkActions[$name] = true;

                    if ($shouldNotify) {
                        $bulkWarnings[$name] = true;
                    }
                } elseif (! $coveredByBulk && $shouldNotify
                    && ($this->jobFeedbackNotifiedStaleAttempts[$execution->id] ?? null) !== $execution->attempt) {
                    $this->jobFeedbackNotifiedStaleAttempts[$execution->id] = $execution->attempt;
                    $this->notifyStaleJobFeedback(bulk: false);
                }
            }

            foreach (array_slice($execution->history, $subscription['cursor']) as $event) {
                if (! $coveredByBulk && ! $followingSubmittedReplacement) {
                    $action->deliverJobUpdate($execution, $event['status'], $event['attempt'], $record);
                }

                if (isset($this->jobFeedbackSubscriptions[$subscriptionKey])) {
                    $this->jobFeedbackSubscriptions[$subscriptionKey]['cursor']++;
                }
            }
        }

        foreach (array_diff_key($bulkWarnings, $this->jobFeedbackNotifiedStaleBulkActions) as $name => $notified) {
            $this->notifyStaleJobFeedback(bulk: true);
            $this->jobFeedbackNotifiedStaleBulkActions[$name] = $notified;
        }

        $this->jobFeedbackNotifiedStaleBulkActions = array_intersect_key($this->jobFeedbackNotifiedStaleBulkActions, $staleBulkActions);
    }

    private function notifyStaleJobFeedback(bool $bulk): void
    {
        Notification::make()
            ->warning()
            ->title($bulk
                ? __('filament-job-feedback::messages.stale_bulk_notification_title')
                : __('filament-job-feedback::messages.stale_notification_title'))
            ->body($bulk
                ? __('filament-job-feedback::messages.stale_bulk_notification_body')
                : __('filament-job-feedback::messages.stale_notification_body'))
            ->send();
    }

    public function isJobFeedbackPending(JobAction|JobBulkAction $action, bool $includeUndelivered = true): bool
    {
        $name = $action->getName();

        if ($action->isHidden() || ! $action->isAuthorized()) {
            return false;
        }

        $actionRecord = $action instanceof JobAction ? $action->getRecord() : null;
        $record = $actionRecord instanceof Model ? $actionRecord : null;
        $row = $action instanceof JobAction && $action->getTable() !== null;

        $recordKey = null;

        if ($row) {
            if (! $this instanceof HasTable || $record === null) {
                return false;
            }

            $recordKey = $this->getTableRecordKey($record);
        }

        $feedback = app(FilamentJobFeedback::class);

        if ($action instanceof JobAction) {
            $key = $action->getDeduplicationKey($record);

            if ($key !== null && ($active = $feedback->active($key)) !== null) {
                $this->subscribeToJobFeedback($active->id, $name, $recordKey, $row, discovered: true);

                return true;
            }
        }

        foreach ($this->jobFeedbackSubscriptions as $subscription) {
            if ($subscription['action'] !== $name || $subscription['row'] !== $row
                || ($action instanceof JobAction && $subscription['record'] !== $recordKey)) {
                continue;
            }

            if ($this->resolveValidJobFeedbackSubscription(
                $subscription,
                fn (Execution $execution): bool => $includeUndelivered
                    ? $this->needsJobFeedback($execution, $subscription['cursor'])
                    : $this->isAwaitingJobFeedback($execution),
            ) !== null) {
                return true;
            }
        }

        return false;
    }

    public function getJobFeedbackPollingInterval(): ?string
    {
        $interval = null;
        $shortest = INF;
        $consider = static function (string $candidate) use (&$interval, &$shortest): void {
            $milliseconds = str_ends_with($candidate, 'ms')
                ? (float) substr($candidate, 0, -2)
                : (float) substr($candidate, 0, -1) * 1000;

            if ($milliseconds < $shortest) {
                $interval = $candidate;
                $shortest = $milliseconds;
            }
        };

        foreach ($this->resolveJobFeedbackDiscoveryActions() as $action) {
            if ($action->shouldDiscoverSharedJobs()) {
                $consider($action->getJobFeedbackPollingInterval());
            }
        }

        foreach ($this->jobFeedbackSubscriptions as $subscription) {
            $resolved = $this->resolveValidJobFeedbackSubscription(
                $subscription,
                fn (Execution $execution): bool => $this->needsJobFeedback($execution, $subscription['cursor']),
            );

            if ($resolved !== null) {
                $consider($resolved['action']->getJobFeedbackPollingInterval());
            }
        }

        return $interval;
    }

    /** @return iterable<JobAction> */
    private function resolveJobFeedbackDiscoveryActions(): iterable
    {
        foreach ($this->jobFeedbackDiscoveryActions as ['action' => $name, 'record' => $recordKey]) {
            $record = null;

            if ($recordKey !== null) {
                $record = $this instanceof HasTable ? $this->getTableRecord($recordKey) : null;

                if (! $record instanceof Model) {
                    continue;
                }
            }

            if (($action = $this->resolveJobFeedbackAction($name, row: $record)) instanceof JobAction) {
                yield $action;
            }
        }
    }

    private function needsJobFeedback(Execution $execution, int $cursor): bool
    {
        return $this->isAwaitingJobFeedback($execution) || $cursor < count($execution->history);
    }

    private function isAwaitingJobFeedback(Execution $execution): bool
    {
        return ! $execution->status->isTerminal()
            && ($execution->identity === null || app(FilamentJobFeedback::class)->hasClaim($execution));
    }

    private function isStaleJobFeedback(Execution $execution): bool
    {
        return $execution->isStale() && $this->isAwaitingJobFeedback($execution);
    }

    public function mountStaleJobFeedbackReplacement(string $subscriptionKey): void
    {
        $subscription = $this->jobFeedbackSubscriptions[$subscriptionKey] ?? null;

        if (! $this instanceof HasActions || $subscription === null
            || ($resolved = $this->resolveValidJobFeedbackSubscription($subscription)) === null) {
            return;
        }

        ['action' => $action] = $resolved;
        $candidates = $this->getStaleJobFeedbackSubscriptionKeys($action);

        if (! in_array($subscriptionKey, $candidates, true)) {
            return;
        }

        $token = (string) Str::uuid();
        $subscriptions = [];
        foreach ($action instanceof JobBulkAction ? $candidates : [$subscriptionKey] as $key) {
            $candidate = app(FilamentJobFeedback::class)->find($this->jobFeedbackSubscriptions[$key]['id']);

            if ($candidate?->isStale()) {
                $subscriptions[$key] = $candidate->attempt;
            }
        }

        if ($subscriptions === []) {
            return;
        }

        $this->jobFeedbackReplacement = ['token' => $token, 'subscriptions' => $subscriptions];

        $context = $action instanceof JobBulkAction
            ? ['table' => true, 'bulk' => true]
            : ($subscription['row'] ? ['table' => true, 'recordKey' => $subscription['record']] : []);

        $this->mountAction($action->getName(), ['filamentJobFeedbackReplacement' => $token], $context);
    }

    /** @internal */
    public function hasMountedStaleJobFeedbackReplacement(Action $action): bool
    {
        $index = $action->getNestingIndex();

        if ($index === null) {
            return false;
        }

        // Filament may keep the action object and its arguments after unmounting the modal.
        $mounted = $this->mountedActions[$index] ?? null;

        return ($mounted['name'] ?? null) === $action->getName()
            && isset($mounted['arguments']['filamentJobFeedbackReplacement']);
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public function getStaleJobFeedbackSubscriptionKeys(JobAction|JobBulkAction $action): array
    {
        if ($action->isHidden() || ! $action->isAuthorized()) {
            return [];
        }

        $row = $action instanceof JobAction && $action->getTable() !== null;
        $actionRecord = $action instanceof JobAction ? $action->getRecord() : null;
        $keys = [];

        foreach ($this->jobFeedbackSubscriptions as $key => $subscription) {
            if ($subscription['action'] !== $action->getName() || $subscription['row'] !== $row
                || ($resolved = $this->resolveValidJobFeedbackSubscription($subscription, $this->isStaleJobFeedback(...))) === null) {
                continue;
            }

            ['action' => $registered, 'record' => $record] = $resolved;

            if (($action instanceof JobBulkAction) !== ($registered instanceof JobBulkAction)
                || ($action instanceof JobAction && ($actionRecord instanceof Model ? ! $actionRecord->is($record) : $record !== null))
                || ! $action->canReplaceStaleJob($record)) {
                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @internal
     *
     * @return array{execution: string, attempt: int, record: Model|null}|null
     */
    public function resolveStaleJobFeedbackReplacement(JobAction|JobBulkAction $action, string $token, ?string $recordKey = null): ?array
    {
        $replacement = $this->jobFeedbackReplacement;

        if ($replacement === null || $token !== $replacement['token']) {
            return null;
        }

        foreach ($replacement['subscriptions'] as $key => $attempt) {
            $subscription = $this->jobFeedbackSubscriptions[$key] ?? null;

            if ($subscription === null || $subscription['action'] !== $action->getName()
                || ($recordKey !== null && $subscription['record'] !== $recordKey)
                || ($resolved = $this->resolveValidJobFeedbackSubscription($subscription)) === null) {
                continue;
            }

            ['execution' => $execution, 'action' => $registered, 'record' => $record] = $resolved;
            $actionRecord = $action instanceof JobAction ? $action->getRecord() : null;

            if (($action instanceof JobBulkAction) !== ($registered instanceof JobBulkAction)
                || ($action instanceof JobAction && ($action->getTable() !== null) !== $subscription['row'])
                || ($action instanceof JobAction && ($actionRecord instanceof Model ? ! $actionRecord->is($record) : $record !== null))
                || ! $action->isAuthorized() || $action->isHidden() || ! $action->canReplaceStaleJob($record)) {
                continue;
            }

            return ['execution' => $execution->id, 'attempt' => $attempt, 'record' => $record];
        }

        return null;
    }

    /**
     * @internal
     *
     * @return list<Model>
     */
    public function getStaleBulkReplacementRecords(JobBulkAction $action): array
    {
        $replacement = $this->jobFeedbackReplacement;
        $token = $action->getArguments()['filamentJobFeedbackReplacement'] ?? null;

        if (! $this instanceof HasTable || $replacement === null || $token !== $replacement['token']) {
            return [];
        }

        $records = [];
        foreach (array_keys($replacement['subscriptions']) as $key) {
            $subscription = $this->jobFeedbackSubscriptions[$key] ?? null;

            if ($subscription !== null && ! $subscription['row'] && $subscription['action'] === $action->getName()
                && $subscription['owner'] === $this->jobFeedbackOwner() && $subscription['record'] !== null
                && ($record = $this->getTableRecord($subscription['record'])) instanceof Model
                && (! $action->shouldAuthorizeIndividualRecords() || $action->getIndividualRecordAuthorizationResponse($record)->allowed())) {
                $records[$subscription['record']] = $record;
            }
        }

        return array_values($records);
    }

    /** @return array<string, array{execution: Execution, status: string}> */
    public function getJobFeedbackStatusRows(): array
    {
        $rows = [];

        foreach ($this->jobFeedbackSubscriptions as $subscription) {
            if (($resolved = $this->resolveValidJobFeedbackSubscription($subscription)) === null) {
                continue;
            }

            ['execution' => $execution] = $resolved;
            $status = ! $execution->status->isTerminal() && ! $this->isAwaitingJobFeedback($execution)
                ? 'inactive_unknown'
                : ($execution->isStale() ? 'stale' : $execution->status->value);
            $rows[$execution->id] ??= ['execution' => $execution, 'status' => $status];
        }

        return $rows;
    }

    /** @return array<string, Execution> */
    public function getJobFeedbackExecutions(): array
    {
        $executions = [];

        foreach ($this->jobFeedbackSubscriptions as $subscription) {
            if (($resolved = $this->resolveValidJobFeedbackSubscription($subscription)) !== null) {
                $execution = $resolved['execution'];
                $executions[$execution->id] = $execution;
            }
        }

        return $executions;
    }

    private function resolveJobFeedbackAction(string $name, bool $bulk = false, ?Model $row = null): JobAction|JobBulkAction|null
    {
        if ($row !== null) {
            $registered = $this instanceof HasTable ? ($this->getTable()->getFlatRecordActions()[$name] ?? null) : null;
            $action = $registered instanceof JobAction ? (clone $registered)->record($row) : null;
        } else {
            $action = $bulk
                ? ($this instanceof HasTable ? $this->getTable()->getBulkAction($name) : null)
                : $this->getAction($name, isMounting: false);
        }

        if (($bulk ? ! $action instanceof JobBulkAction : ! $action instanceof JobAction) || $action->isHidden() || ! $action->isAuthorized()) {
            return null;
        }

        return $action;
    }

    private function subscribeToJobFeedback(string $id, string $action, ?string $record = null, bool $row = false, ?string $identity = null, bool $discovered = false, bool $followsReplacement = false): void
    {
        $key = hash('sha256', serialize([$id, $action, $record, $row]));
        $this->jobFeedbackSubscriptions[$key] ??= [
            'id' => $id,
            'action' => $action,
            'cursor' => 0,
            'owner' => $this->jobFeedbackOwner(),
            'record' => $record,
            'row' => $row,
            'identity' => $identity,
            'discovered' => $discovered,
            'followsReplacement' => $followsReplacement,
            'subscribedAt' => now()->getTimestamp(),
        ];

        if (! $discovered && ! $followsReplacement) {
            $this->jobFeedbackSubscriptions[$key]['discovered'] = false;
            $this->jobFeedbackSubscriptions[$key]['followsReplacement'] = false;
        }
    }

    /**
     * @param  JobFeedbackSubscription  $subscription
     * @param  (Closure(Execution): bool)|null  $shouldResolve
     * @return array{execution: Execution, action: JobAction|JobBulkAction, record: Model|null}|null
     */
    private function resolveValidJobFeedbackSubscription(array $subscription, ?Closure $shouldResolve = null): ?array
    {
        $execution = app(FilamentJobFeedback::class)->find($subscription['id']);

        if ($execution === null || ($shouldResolve !== null && ! $shouldResolve($execution))
            || ($context = $this->resolveJobFeedbackSubscription($subscription)) === null) {
            return null;
        }

        [$action, $record] = $context;

        return $this->matchesJobFeedbackIdentity($execution, $action, $record, $subscription['identity'] ?? null)
            ? ['execution' => $execution, 'action' => $action, 'record' => $record]
            : null;
    }

    /**
     * @param  JobFeedbackSubscription  $subscription
     * @return array{JobAction|JobBulkAction, Model|null}|null
     */
    private function resolveJobFeedbackSubscription(array $subscription): ?array
    {
        if ($subscription['owner'] !== $this->jobFeedbackOwner()) {
            return null;
        }

        $recordKey = $subscription['record'];

        if ($subscription['row']) {
            $record = $this instanceof HasTable ? $this->getTableRecord($recordKey) : null;

            if (! $record instanceof Model) {
                return null;
            }

            $action = $this->resolveJobFeedbackAction($subscription['action'], row: $record);

            return $action instanceof JobAction ? [$action, $record] : null;
        }

        $action = $this->resolveJobFeedbackAction($subscription['action'], $recordKey !== null);

        if ($action instanceof JobAction) {
            $record = $action->getRecord();

            return [$action, $record instanceof Model ? $record : null];
        }

        if (! $action instanceof JobBulkAction || ! $this instanceof HasTable) {
            return null;
        }

        $record = $this->getTableRecord($recordKey);

        return $record instanceof Model && (! $action->shouldAuthorizeIndividualRecords() || $action->getIndividualRecordAuthorizationResponse($record)->allowed())
            ? [$action, $record]
            : null;
    }

    private function matchesJobFeedbackIdentity(Execution $execution, JobAction|JobBulkAction $action, ?Model $record, ?string $identity): bool
    {
        if ($action->discoversJobIdentity()) {
            $key = $action->getDeduplicationKey($record);
            $identity = $key === null ? null : hash('sha256', $key);
        }

        return $execution->identity === $identity;
    }

    protected function jobFeedbackOwner(): ?string
    {
        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }
}
