<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use SkyRaptor\FilamentJobFeedback\Actions\JobAction;
use SkyRaptor\FilamentJobFeedback\FilamentJobFeedback;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\DeduplicatedFeedbackJob;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\JobActionHost;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\UniqueFeedbackJob;

beforeEach(function (): void {
    $this->useDatabaseQueueForFeedback();
    Gate::define('follow-feedback', fn (?GenericUser $user): bool => true);
});

it('keeps a confirmation modal mounted while polling', function (): void {
    Livewire::test(JobActionHost::class)
        ->mountAction('confirm')
        ->call('pollJobFeedback')
        ->assertActionMounted('confirm');
});

it('marks a pending action spinner as visible to Filament button styles', function (): void {
    $browser = Livewire::test(JobActionHost::class)->callAction('shared');
    $icon = $browser->instance()->getAction('shared')->getIcon();

    expect($icon->toHtml())->toContain('fi-loading-indicator')->toMatch('/(?:\s)fi-loading(?:\s|")/');
    $browser->assertActionDisabled('shared')->assertSeeHtml('aria-busy="true"');
});

it('preserves unsubmitted action form data while polling', function (): void {
    Livewire::test(JobActionHost::class)
        ->mountAction('configure')
        ->setActionData(['enabled' => false])
        ->call('pollJobFeedback')
        ->assertActionMounted('configure')
        ->assertActionDataSet(['enabled' => false]);
});

it('lets separate admins follow one shared job and delivers each callback once per tab', function (): void {
    $adminA = new GenericUser(['id' => 1]);
    $adminB = new GenericUser(['id' => 2]);
    Gate::define('follow-feedback', fn (GenericUser $user): bool => in_array($user->getAuthIdentifier(), [1, 2], true));

    $this->actingAs($adminA);
    $first = Livewire::test(JobActionHost::class)
        ->call('mountAction', 'shared')
        ->call('callMountedAction')
        ->assertSee('Job queued.');

    $this->actingAs($adminB);
    $second = Livewire::test(JobActionHost::class)
        ->call('pollJobFeedback')
        ->assertSee('Job queued.')
        ->call('mountAction', 'shared')
        ->call('callMountedAction');

    $this->actingAs(new GenericUser(['id' => 3]));
    Livewire::test(JobActionHost::class)->call('pollJobFeedback')->assertDontSee('Job queued.');

    expect(DB::table('jobs')->count())->toBe(1);
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    foreach ([[$first, $adminA], [$second, $adminB]] as [$browser, $admin]) {
        $this->actingAs($admin);
        $browser->call('pollJobFeedback')
            ->assertSee('Job completed.')
            ->assertSet('sharedEvents', ['queued', 'processing', 'completed'])
            ->call('pollJobFeedback')
            ->assertSet('sharedEvents', ['queued', 'processing', 'completed']);
    }

    Gate::define('follow-feedback', fn (): bool => false);
    $second->call('pollJobFeedback')->assertDontSee('Job completed.')->assertDontSee('Job queued.');
});

it('shows two independent report runs and skips dispatch when reports are disabled', function (): void {
    $first = Livewire::test(JobActionHost::class)
        ->callAction('submit', data: ['enabled' => false])
        ->assertDontSee('Job queued.');

    expect(DB::table('jobs')->count())->toBe(0);

    $first->callAction('submit', data: ['enabled' => true])->assertSee('Job queued.');
    $second = Livewire::test(JobActionHost::class)
        ->call('pollJobFeedback')->assertDontSee('Job queued.')
        ->callAction('submit', data: ['enabled' => true])->assertSee('Job queued.');

    expect(DB::table('jobs')->count())->toBe(2);
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    $first->call('pollJobFeedback')->assertSee('Job completed.');
    $second->call('pollJobFeedback')->assertSee('Job queued.')->assertDontSee('Job completed.');
});

it('lets an authorized Filament action follow an ordinary Laravel dispatch', function (): void {
    Bus::dispatch(new DeduplicatedFeedbackJob);

    $browser = Livewire::test(JobActionHost::class)
        ->assertActionDisabled('shared')
        ->assertSee('wire:poll.', escape: false)
        ->call('pollJobFeedback')
        ->assertSet('sharedEvents', ['queued']);

    expect(DB::table('jobs')->count())->toBe(1);
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    $browser->call('pollJobFeedback')
        ->assertSet('sharedEvents', ['queued', 'processing', 'completed'])
        ->assertActionEnabled('shared')
        ->assertDontSee('wire:poll.', escape: false);
});

it('waits for the outer transaction before claiming and subscribing to an after-commit job', function (): void {
    $job = (new DeduplicatedFeedbackJob)->afterCommit();
    $key = FilamentJobFeedback::deduplicationKey($job);
    assert(is_string($key));
    $feedback = app(FilamentJobFeedback::class);

    JobAction::configureUsing(
        fn (JobAction $action): JobAction => $action->databaseTransaction()->before(fn () => $action->job(fn () => $job)),
        function () use ($feedback, $key): void {
            $browser = Livewire::test(JobActionHost::class);
            DB::beginTransaction();

            try {
                $browser->callAction('submit', data: ['enabled' => true])
                    ->assertActionEnabled('submit')
                    ->assertDontSee('Job queued.');

                expect(DB::table('jobs')->count())->toBe(0)
                    ->and($feedback->active($key))->toBeNull();
            } finally {
                DB::rollBack();
            }

            $browser->callAction('submit', data: ['enabled' => true])
                ->assertActionDisabled('submit')
                ->assertSee('Job queued.');

            expect(DB::table('jobs')->count())->toBe(1)
                ->and($feedback->active($key))->not->toBeNull();
        },
        isImportant: true,
    );
});

it('releases an immediate database queue claim when Filament rolls back', function (): void {
    config(['queue.connections.database.after_commit' => true]);
    $job = (new DeduplicatedFeedbackJob)->beforeCommit();
    $key = FilamentJobFeedback::deduplicationKey($job);
    assert(is_string($key));
    $feedback = app(FilamentJobFeedback::class);

    JobAction::configureUsing(
        fn (JobAction $action): JobAction => $action->databaseTransaction()
            ->before(fn () => $action->job(fn () => $job))
            ->after(function () use ($action): void {
                expect(DB::table('jobs')->count())->toBe(1);
                $action->halt(shouldRollBackDatabaseTransaction: true);
            }),
        fn () => Livewire::test(JobActionHost::class)
            ->callAction('submit', data: ['enabled' => true])
            ->assertDontSee('Job queued.'),
        isImportant: true,
    );

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($feedback->active($key))->toBeNull();
});

it('joins a contract job supplied by a submit-only factory and continues polling it', function (): void {
    Bus::dispatch(new DeduplicatedFeedbackJob);

    JobAction::configureUsing(
        fn (JobAction $action): JobAction => $action->before(fn () => $action->job(fn (): DeduplicatedFeedbackJob => new DeduplicatedFeedbackJob)),
        function (): void {
            $browser = Livewire::test(JobActionHost::class)
                ->assertActionEnabled('submit')
                ->callAction('submit', data: ['enabled' => true, 'retry' => true])
                ->assertActionDisabled('submit')
                ->assertSee('Job queued.');

            expect(DB::table('jobs')->count())->toBe(1);
            $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

            $browser->call('pollJobFeedback')->assertSee('Job completed.')->assertActionEnabled('submit');
        },
        isImportant: true,
    );
});

it('notifies without subscribing when a Laravel unique job is already queued externally', function (): void {
    config(['cache.stores.unique-jobs' => ['driver' => 'array']]);
    UniqueFeedbackJob::dispatch();

    JobAction::configureUsing(
        fn (JobAction $action): JobAction => $action->before(fn () => $action->job(fn (): UniqueFeedbackJob => new UniqueFeedbackJob)),
        fn () => Livewire::test(JobActionHost::class)
            ->callAction('submit', data: ['enabled' => true])
            ->assertNotified(__('filament-job-feedback::messages.unique_job_blocked'))
            ->assertActionEnabled('submit')
            ->assertDontSee('wire:poll.', escape: false),
        isImportant: true,
    );

    expect(DB::table('jobs')->count())->toBe(1);
});

it('shows a worker timeout and delivers it to the retry callback only once', function (): void {
    $browser = Livewire::test(JobActionHost::class)->callAction('shared');
    $reserved = Queue::connection('database')->pop();
    assert($reserved !== null);
    event(new JobProcessing('database', $reserved));
    event(new JobTimedOut('database', $reserved));
    event(new JobTimedOut('database', $reserved));

    $browser->call('pollJobFeedback')
        ->assertSee('Job timed out; waiting for retry.')
        ->assertActionDisabled('shared')
        ->assertSet('sharedEvents', ['queued', 'processing', 'timed_out'])
        ->call('pollJobFeedback')
        ->assertSet('sharedEvents', ['queued', 'processing', 'timed_out']);
});

it('renders retry and terminal failure in the action host', function (): void {
    $browser = Livewire::test(JobActionHost::class)
        ->assertDontSee('wire:poll.', escape: false)
        ->callAction('submit', data: ['enabled' => true, 'retry' => true])
        ->assertActionDisabled('submit')
        ->assertSee('wire:poll.750ms="pollJobFeedback"', escape: false)
        ->assertSee('Job queued.');

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    $browser->call('pollJobFeedback')->assertSee('Job waiting to retry.')->assertActionDisabled('submit');

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    $browser->call('pollJobFeedback')
        ->assertSee('Job failed.')
        ->assertActionEnabled('submit')
        ->assertDontSee('wire:poll.', escape: false);
});

it('discovers shared runs on render by default and can opt into idle discovery', function (): void {
    $idle = Livewire::test(JobActionHost::class)
        ->assertActionEnabled('shared')
        ->assertDontSee('wire:poll.', escape: false);

    config(['filament-job-feedback.discover_shared_jobs' => true, 'filament-job-feedback.polling_interval' => '5s']);
    $observer = Livewire::test(JobActionHost::class)
        ->assertActionEnabled('shared')
        ->assertSee('wire:poll.5s="pollJobFeedback"', escape: false);
    config(['filament-job-feedback.discover_shared_jobs' => false]);

    $runner = Livewire::test(JobActionHost::class)->callAction('shared')
        ->assertActionDisabled('shared')
        ->assertSee('wire:poll.5s="pollJobFeedback"', escape: false);

    config(['filament-job-feedback.discover_shared_jobs' => true]);
    $observer->call('pollJobFeedback')->assertActionDisabled('shared')->assertSet('sharedEvents', ['queued']);
    config(['filament-job-feedback.discover_shared_jobs' => false]);
    $idle->call('$refresh')->assertActionDisabled('shared')->assertSee('wire:poll.5s="pollJobFeedback"', escape: false);
    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    $runner->call('pollJobFeedback')->assertActionEnabled('shared')->assertDontSee('wire:poll.', escape: false);
    $idle->call('pollJobFeedback')->assertSet('sharedEvents', ['queued', 'processing', 'completed']);
});
