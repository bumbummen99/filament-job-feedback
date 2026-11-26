<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use SkyRaptor\FilamentJobFeedback\Contracts\ExecutionMonitor;
use SkyRaptor\FilamentJobFeedback\Contracts\ExecutionReconciler;
use SkyRaptor\FilamentJobFeedback\Execution;
use SkyRaptor\FilamentJobFeedback\ExecutionClaimState;
use SkyRaptor\FilamentJobFeedback\ExecutionStatus;
use SkyRaptor\FilamentJobFeedback\FilamentJobFeedback;
use SkyRaptor\FilamentJobFeedback\LaravelQueueMonitor;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\DeduplicatedFeedbackJob;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\JobActionHost;

function processingStaleClaim(string $id, int $attempt = 1, ?int $timeout = 10): Job
{
    $job = Mockery::mock(Job::class);
    $job->allows([
        'payload' => [LaravelQueueMonitor::PayloadKey => $id],
        'timeout' => $timeout,
        'attempts' => $attempt,
        'isReleased' => false,
        'hasFailed' => false,
    ]);

    event(new JobProcessing('test', $job));

    return $job;
}

it('estimates staleness from the exact attempt timeout and grace without expiring the claim', function (): void {
    Queue::fake();
    config(['filament-job-feedback.stale_grace_seconds' => 5]);
    $feedback = app(FilamentJobFeedback::class);
    $job = fn () => new DeduplicatedFeedbackJob;
    $id = $feedback->start($job);
    expect($feedback->find($id)->isStale())->toBeFalse();
    processingStaleClaim($id);

    $this->travel(14)->seconds();
    expect($feedback->find($id)->isStale())->toBeFalse();
    $this->travel(1)->second();
    expect($feedback->find($id)->isStale())->toBeTrue()
        ->and($feedback->start($job))->toBe($id);

    processingStaleClaim($id, attempt: 2);
    expect($feedback->start($job, replaceStale: $id, expectedAttempt: 1))->toBeNull()
        ->and($feedback->find($id)->isStale())->toBeFalse();
    Queue::assertPushed(DeduplicatedFeedbackJob::class, 1);
});

it('replaces a confirmed stale claim once without mixing up late events', function (): void {
    Queue::fake();
    config(['filament-job-feedback.stale_grace_seconds' => 0]);
    $feedback = app(FilamentJobFeedback::class);
    $job = fn () => new DeduplicatedFeedbackJob;
    $old = $feedback->start($job);
    $attempt = processingStaleClaim($old);

    $this->travel(10)->seconds();
    $replacement = $feedback->start($job, replaceStale: $old, expectedAttempt: 1);
    expect($replacement)->not->toBeNull()->not->toBe($old)
        ->and($feedback->start($job, replaceStale: $old, expectedAttempt: 1))->toBe($replacement);

    event(new JobProcessed('test', $attempt));
    expect($feedback->find($old)->status)->toBe(ExecutionStatus::Completed)
        ->and($feedback->start($job))->toBe($replacement);
    Queue::assertPushed(DeduplicatedFeedbackJob::class, 2);
});

it('uses the configured worker fallback only for jobs without a declared timeout and ignores retry waits', function (): void {
    Queue::fake();
    config([
        'filament-job-feedback.stale_fallback_timeout_seconds' => 5,
        'filament-job-feedback.stale_grace_seconds' => 0,
    ]);
    $feedback = app(FilamentJobFeedback::class);
    $fallback = $feedback->start(fn () => new DeduplicatedFeedbackJob('implicit'));
    $unbounded = $feedback->start(fn () => new DeduplicatedFeedbackJob('disabled'));
    processingStaleClaim($fallback, timeout: null);
    processingStaleClaim($unbounded, timeout: 0);

    $this->travel(5)->seconds();
    expect($feedback->find($fallback)->isStale())->toBeTrue()
        ->and($feedback->find($unbounded)->isStale())->toBeFalse();

    app(ExecutionMonitor::class)->record($fallback, ExecutionStatus::Retrying, 1);
    $this->travel(1)->hour();
    expect($feedback->find($fallback)->isStale())->toBeFalse();
});

it('automatically releases only a claim the integration confirms cannot run again', function (): void {
    Queue::fake();
    $feedback = app(FilamentJobFeedback::class);
    $job = fn () => new DeduplicatedFeedbackJob;
    $id = $feedback->start($job);
    processingStaleClaim($id, timeout: null);
    $this->travel(1)->hour();
    expect($feedback->find($id)->isStale())->toBeFalse();

    $integration = new class implements ExecutionReconciler
    {
        public ExecutionClaimState $state = ExecutionClaimState::Unknown;

        public function state(Execution $execution): ExecutionClaimState
        {
            return $this->state;
        }
    };
    app()->instance(ExecutionReconciler::class, $integration);
    $key = FilamentJobFeedback::deduplicationKey($job());
    expect($feedback->active($key)->id)->toBe($id);
    $integration->state = ExecutionClaimState::Active;
    expect($feedback->active($key)->id)->toBe($id);
    $integration->state = ExecutionClaimState::Terminal;
    expect($feedback->active($key))->toBeNull()
        ->and($feedback->find($id)->status)->toBe(ExecutionStatus::Processing);
    Queue::assertPushed(DeduplicatedFeedbackJob::class, 1);
});

it('does not notify when a stale run has no recovery badge', function (): void {
    Queue::fake();
    Gate::define('follow-feedback', fn (?GenericUser $user): bool => true);
    $this->freezeTime();
    config(['filament-job-feedback.stale_grace_seconds' => 0]);

    $page = Livewire::test(JobActionHost::class)
        ->call('mountAction', 'shared')
        ->call('callMountedAction');
    $subscription = array_values($page->get('jobFeedbackSubscriptions'))[0];
    processingStaleClaim($subscription['id']);

    $this->travel(10)->seconds();
    $page->call('pollJobFeedback')
        ->assertSee('No recent update')
        ->assertNotNotified('Job run may be stale');
});

it('reopens and validates the original form before replacing a stale claim', function (): void {
    Queue::fake();
    config(['filament-job-feedback.stale_grace_seconds' => 0]);
    $component = Livewire::test(JobActionHost::class)
        ->call('mountAction', 'recoverable')
        ->set('mountedActions.0.data.enabled', true)
        ->call('callMountedAction')
        ->assertDontSee('Start a new run');
    $subscriptions = $component->get('jobFeedbackSubscriptions');
    $key = array_key_first($subscriptions);
    $old = $subscriptions[$key]['id'];
    processingStaleClaim($old);

    $this->travel(10)->seconds();
    $component->call('pollJobFeedback')
        ->assertSee('No recent update')
        ->assertSee('Recoverable')
        ->assertSee('Stale')
        ->assertDontSee('Recoverable — Start a new run')
        ->call('mountStaleJobFeedbackReplacement', $key)
        ->assertSet('mountedActions.0.name', 'recoverable');
    $component->call('unmountAction');
    Queue::assertPushed(DeduplicatedFeedbackJob::class, 1);

    $component->call('mountStaleJobFeedbackReplacement', $key)
        ->set('mountedActions.0.data.alternate', true)
        ->call('callMountedAction');
    Queue::assertPushed(DeduplicatedFeedbackJob::class, 1);

    $component->call('mountStaleJobFeedbackReplacement', $key)
        ->set('mountedActions.0.data.enabled', true)
        ->call('callMountedAction')
        ->assertSee('No longer the active run. Outcome unknown.')
        ->assertSeeHtml('aria-busy="true"');
    Queue::assertPushed(DeduplicatedFeedbackJob::class, 2);
});

it('hands a submit-only subscription over to a replacement started in another tab', function (): void {
    Queue::fake();
    config(['filament-job-feedback.stale_grace_seconds' => 0]);
    $submit = fn () => Livewire::test(JobActionHost::class)
        ->call('mountAction', 'recoverable')
        ->set('mountedActions.0.data.enabled', true)
        ->call('callMountedAction');
    $first = $submit();
    $second = $submit();
    $key = array_key_first($first->get('jobFeedbackSubscriptions'));
    $old = $first->get('jobFeedbackSubscriptions')[$key]['id'];
    processingStaleClaim($old);

    $this->travel(10)->seconds();
    $first->call('mountStaleJobFeedbackReplacement', $key)->call('callMountedAction');
    $second->call('pollJobFeedback')->assertSeeHtml('aria-busy="true"');
    expect(array_column($second->get('jobFeedbackSubscriptions'), 'id'))
        ->toBe(array_column($first->get('jobFeedbackSubscriptions'), 'id'));
    Queue::assertPushed(DeduplicatedFeedbackJob::class, 2);
});
