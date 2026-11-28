<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Actions\Concerns;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use SkyRaptor\FilamentJobFeedback\Actions\HasJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\JobAction;
use SkyRaptor\FilamentJobFeedback\Execution;
use SkyRaptor\FilamentJobFeedback\ExecutionStatus;
use SkyRaptor\FilamentJobFeedback\FilamentJobFeedback;

use function Filament\Support\generate_loading_indicator_html;

trait ConfiguresJobFeedback
{
    protected ShouldQueue|Closure|null $jobToQueue = null;

    protected bool $discoverJobFromFactory = false;

    protected bool|Closure $queueCondition = true;

    protected ?string $pollingInterval = null;

    protected ?bool $discoverSharedJobs = null;

    protected bool|Closure $allowStaleJobReplacement = false;

    /** @var array<string, Closure> */
    protected array $jobCallbacks = [];

    protected function shouldQueueJob(?Model $record = null): bool
    {
        return (bool) $this->evaluate($this->queueCondition, $record === null ? [] : ['record' => $record], $this->recordInjections($record));
    }

    protected function makeQueuedJob(?Model $record = null): ShouldQueue
    {
        $job = $this->jobToQueue instanceof Closure
            ? $this->evaluate($this->jobToQueue, $record === null ? [] : ['record' => $record], $this->recordInjections($record))
            : $this->jobToQueue;

        if (! $job instanceof ShouldQueue) {
            throw new LogicException('The job action must provide an instance of ShouldQueue.');
        }

        return $job;
    }

    public function job(ShouldQueue|Closure $job, bool $discover = false): static
    {
        $this->jobToQueue = $job;
        $this->discoverJobFromFactory = $discover;

        return $this;
    }

    public function queueIf(bool|Closure $condition): static
    {
        $this->queueCondition = $condition;

        return $this;
    }

    public function pollingInterval(string $interval): static
    {
        $this->pollingInterval = $this->validatePollingInterval($interval);

        return $this;
    }

    public function discoverSharedJobs(bool $condition = true): static
    {
        $this->discoverSharedJobs = $condition;

        return $this;
    }

    public function allowStaleJobReplacement(bool|Closure $condition = true): static
    {
        $this->allowStaleJobReplacement = $condition;

        return $this;
    }

    /** @internal */
    public function canReplaceStaleJob(?Model $record): bool
    {
        return ! parent::isDisabled() && (bool) $this->evaluate($this->allowStaleJobReplacement, ['record' => $record], $this->recordInjections($record));
    }

    public function isDisabled(): bool
    {
        return parent::isDisabled() || ($this->isStaleJobReplacementRequest()
            ? $this->getStaleJobReplacement() === null
            : ($this->hasPendingJobFeedback() && $this->getStaleRecoveryTrigger() === null));
    }

    public function getBadge(): ?string
    {
        if ($this->getStaleRecoveryTrigger() !== null) {
            // Filament's attached action badge takes text, not a separate icon.
            return "\u{26A0}\u{FE0E} ".__('filament-job-feedback::messages.stale_badge');
        }

        $badge = parent::getBadge();

        return $badge === null ? null : (string) $badge;
    }

    /** @return string|array<string>|null */
    public function getBadgeColor(?string $badge = null): string|array|null
    {
        return $this->getStaleRecoveryTrigger() === null ? parent::getBadgeColor($badge) : 'warning';
    }

    public function getLivewireClickHandler(): ?string
    {
        $key = $this->getStaleRecoveryTrigger();

        return $key === null ? parent::getLivewireClickHandler() : "mountStaleJobFeedbackReplacement('{$key}')";
    }

    public function getAlpineClickHandler(): ?string
    {
        return $this->getStaleRecoveryTrigger() === null ? parent::getAlpineClickHandler() : null;
    }

    public function getLivewireTarget(): ?string
    {
        return $this->getStaleRecoveryTrigger() === null
            ? parent::getLivewireTarget()
            : $this->getLivewireClickHandler();
    }

    private function getStaleRecoveryTrigger(): ?string
    {
        $livewire = $this->getLivewire();

        return $this->canShowStaleJobFeedback()
            && $livewire instanceof HasJobFeedback && method_exists($livewire, 'getStaleJobFeedbackSubscriptionKeys')
            ? ($livewire->getStaleJobFeedbackSubscriptionKeys($this)[0] ?? null)
            : null;
    }

    /** @internal */
    public function canShowStaleJobFeedback(): bool
    {
        return $this->allowStaleJobReplacement !== false && ! $this->isStaleJobReplacementRequest() && $this->isLivewireClickHandlerEnabled();
    }

    public function shouldOpenModal(?Closure $checkForSchemaUsing = null): bool
    {
        return $this->getStaleJobReplacement() !== null || parent::shouldOpenModal($checkForSchemaUsing);
    }

    public function getModalSubmitActionLabel(): string
    {
        return $this->getStaleJobReplacement() === null
            ? parent::getModalSubmitActionLabel()
            : __('filament-job-feedback::messages.start_new_run');
    }

    public function getModalCancelActionLabel(): string
    {
        return $this->getStaleJobReplacement() === null
            ? parent::getModalCancelActionLabel()
            : __('filament-job-feedback::messages.keep_waiting');
    }

    public function getModalCancelAction(): ?Action
    {
        $action = parent::getModalCancelAction();

        if ($action !== null && $this->isStaleJobReplacementRequest() && $action->getLivewireTarget() === null) {
            $action->livewireTarget($this->getLivewireCallMountedActionName());
        }

        return $action;
    }

    public function getModalEyebrow(): string|Htmlable|null
    {
        $eyebrow = $this->getNativeModalEyebrowMethod()?->invoke($this);
        $eyebrow = is_string($eyebrow) || $eyebrow instanceof Htmlable ? $eyebrow : null;

        return $this->getStaleJobReplacement() === null
            ? $eyebrow
            : new HtmlString(view('filament-job-feedback::stale-replacement-warning', ['eyebrow' => $eyebrow])->render());
    }

    public function getModalHeading(): string|Htmlable
    {
        $heading = parent::getModalHeading();

        return $this->getNativeModalEyebrowMethod() !== null || $this->getStaleJobReplacement() === null
            ? $heading
            : new HtmlString(view('filament-job-feedback::stale-replacement-warning', ['heading' => $heading])->render());
    }

    private function getNativeModalEyebrowMethod(): ?ReflectionMethod
    {
        // Early Filament 4 releases do not have a separate slot above the heading.
        $action = new ReflectionClass(Action::class);

        return $action->hasMethod('getModalEyebrow') ? $action->getMethod('getModalEyebrow') : null;
    }

    /** @return array{execution: string, attempt: int, record: Model|null}|null */
    protected function getStaleJobReplacement(): ?array
    {
        $livewire = $this->getLivewire();
        $token = $this->getArguments()['filamentJobFeedbackReplacement'] ?? null;

        return $this->isStaleJobReplacementRequest() && $livewire instanceof HasJobFeedback && is_string($token) && method_exists($livewire, 'resolveStaleJobFeedbackReplacement')
            ? $livewire->resolveStaleJobFeedbackReplacement($this, $token)
            : null;
    }

    protected function isStaleJobReplacementRequest(): bool
    {
        $livewire = $this->getLivewire();

        return array_key_exists('filamentJobFeedbackReplacement', $this->getArguments())
            && method_exists($livewire, 'hasMountedStaleJobFeedbackReplacement')
            && $livewire->hasMountedStaleJobFeedbackReplacement($this);
    }

    public function getIcon(string|BackedEnum|Htmlable|null $default = null): string|BackedEnum|Htmlable|null
    {
        return $this->hasPendingJobFeedback(includeUndelivered: false)
            ? generate_loading_indicator_html(new ComponentAttributeBag(['class' => 'fi-loading']))
            : parent::getIcon($default);
    }

    public function hasExtraAttributes(): bool
    {
        return $this->getLivewire() instanceof HasJobFeedback || parent::hasExtraAttributes();
    }

    /** @return array<mixed> */
    public function getExtraAttributes(): array
    {
        $attributes = parent::getExtraAttributes();
        $livewire = $this->getLivewire();

        if ($this instanceof JobAction && $livewire instanceof HasJobFeedback) {
            $livewire->rememberJobFeedbackAction($this);
        }

        if ($this->getStaleRecoveryTrigger() !== null) {
            $attributes['data-job-feedback-stale'] = 'true';
            $attributes['aria-description'] ??= __('filament-job-feedback::messages.stale_action_description');
        }

        if ($this->hasPendingJobFeedback(includeUndelivered: false)) {
            $attributes['aria-busy'] = 'true';
        }

        return $attributes;
    }

    private function hasPendingJobFeedback(bool $includeUndelivered = true): bool
    {
        $livewire = $this->getLivewire();

        return $livewire instanceof HasJobFeedback && $livewire->isJobFeedbackPending($this, $includeUndelivered);
    }

    /** @internal */
    public function getJobFeedbackPollingInterval(): string
    {
        return $this->pollingInterval ?? $this->validatePollingInterval(config()->string('filament-job-feedback.polling_interval'));
    }

    /** @internal */
    public function shouldDiscoverSharedJobs(): bool
    {
        if (! $this instanceof JobAction || ! ($this->discoverSharedJobs ?? config()->boolean('filament-job-feedback.discover_shared_jobs'))) {
            return false;
        }

        $record = $this->getRecord();

        return $this->getLivewire() instanceof HasJobFeedback
            && ($this->getTable() === null || $record instanceof Model)
            && ! $this->isHidden()
            && $this->isAuthorized()
            && $this->getDeduplicationKey($record instanceof Model ? $record : null) !== null;
    }

    private function validatePollingInterval(string $interval): string
    {
        if (preg_match('/\A[1-9][0-9]*(?:ms|s)\z/', $interval) !== 1) {
            throw new InvalidArgumentException('Use a positive polling interval with a unit such as 750ms or 3s.');
        }

        return $interval;
    }

    public function url(string|Closure|null $url, bool|Closure|null $shouldOpenInNewTab = null): static
    {
        if ($url !== null) {
            throw new LogicException('Job actions cannot open a URL.');
        }

        return $this;
    }

    public function getUrl(): ?string
    {
        return null;
    }

    public function onJobQueued(Closure $callback): static
    {
        return $this->on(ExecutionStatus::Queued, $callback);
    }

    public function onJobProcessing(Closure $callback): static
    {
        return $this->on(ExecutionStatus::Processing, $callback);
    }

    public function onJobRetrying(Closure $callback): static
    {
        return $this->on(ExecutionStatus::Retrying, $callback);
    }

    public function onJobCompleted(Closure $callback): static
    {
        return $this->on(ExecutionStatus::Completed, $callback);
    }

    public function onJobFailed(Closure $callback): static
    {
        return $this->on(ExecutionStatus::Failed, $callback);
    }

    /** @internal */
    public function getDeduplicationKey(?Model $record = null): ?string
    {
        if ($this->jobToQueue instanceof ShouldQueue) {
            return FilamentJobFeedback::deduplicationKey($this->jobToQueue);
        }

        return $this->discoversJobIdentity() ? FilamentJobFeedback::deduplicationKey($this->makeQueuedJob($record)) : null;
    }

    /** @internal */
    public function discoversJobIdentity(): bool
    {
        return $this->jobToQueue instanceof ShouldQueue || $this->discoverJobFromFactory;
    }

    /** @internal */
    public function deliverJobUpdate(Execution $execution, ExecutionStatus $status, int $attempt, ?Model $record = null): void
    {
        $callbackStatus = $status === ExecutionStatus::TimedOut ? ExecutionStatus::Retrying : $status;

        $this->evaluate(
            $this->jobCallbacks[$callbackStatus->value] ?? null,
            ['execution' => $execution, 'status' => $status, 'attempt' => $attempt] + ($record === null ? [] : ['record' => $record]),
            [Execution::class => $execution, ExecutionStatus::class => $status] + $this->recordInjections($record),
        );
    }

    /** @return array<class-string<Model>, Model> */
    private function recordInjections(?Model $record): array
    {
        return $record === null ? [] : [$record::class => $record, Model::class => $record];
    }

    private function on(ExecutionStatus $status, Closure $callback): static
    {
        $this->jobCallbacks[$status->value] = $callback;

        return $this;
    }
}
