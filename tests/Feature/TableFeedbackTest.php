<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use SkyRaptor\FilamentJobFeedback\Actions\JobBulkAction;
use SkyRaptor\FilamentJobFeedback\CacheExecutionStore;
use SkyRaptor\FilamentJobFeedback\FilamentJobFeedback;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\FeedbackRecord;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\JobActionHost;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\JobTableHost;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\ProcessFeedbackRecord;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\UniqueFeedbackJob;

beforeEach(function (): void {
    $this->useDatabaseQueueForFeedback();
    Schema::create('feedback_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->boolean('eligible');
        $table->boolean('processed')->default(false);
    });
    Gate::define('process-feedback-record', fn (?Authenticatable $user, FeedbackRecord $record): bool => $record->eligible);
});

function feedbackRecord(string $name, bool $eligible = true): FeedbackRecord
{
    return FeedbackRecord::query()->create(['name' => $name, 'eligible' => $eligible]);
}

it('continues a bulk submission when native uniqueness blocks an individual record', function (): void {
    config(['cache.stores.unique-jobs' => ['driver' => 'array']]);
    $blocked = feedbackRecord('Blocked');
    $allowed = feedbackRecord('Allowed');
    UniqueFeedbackJob::dispatch($blocked->name);

    JobBulkAction::configureUsing(
        fn (JobBulkAction $action): JobBulkAction => $action->before(fn () => $action->job(fn (FeedbackRecord $record): UniqueFeedbackJob => new UniqueFeedbackJob($record->name))),
        function () use ($blocked, $allowed): void {
            $browser = Livewire::test(JobTableHost::class)
                ->callTableBulkAction('processSelected', [$blocked, $allowed])
                ->assertNotified(Notification::make()
                    ->title('1 of 2 job requests accepted')
                    ->body('One job was not queued because a matching job already holds its unique lock.')
                    ->warning())
                ->assertTableActionEnabled('process', $blocked)
                ->assertTableBulkActionDisabled('processSelected');

            expect($browser->instance()->getJobFeedbackExecutions())->toHaveCount(1);
        },
        isImportant: true,
    );

    expect(DB::table('jobs')->count())->toBe(2);
});

it('processes only eligible selected records and joins active runs', function (): void {
    $first = feedbackRecord('First');
    $second = feedbackRecord('Second');
    $denied = feedbackRecord('Draft', eligible: false);
    $unselected = feedbackRecord('Unselected');

    $browser = Livewire::test(JobTableHost::class)
        ->callTableBulkAction('processSelected', [$first, $second, $denied])
        ->assertNotified(Notification::make()
            ->title('2 of 3 job requests accepted')
            ->body('1 selected record is not authorized for this action.')
            ->warning())
        ->assertTableBulkActionDisabled('processSelected')
        ->assertTableActionDisabled('process', $first)
        ->assertTableActionEnabled('process', $unselected)
        ->assertSee('Job queued.');

    $anotherTab = Livewire::test(JobTableHost::class)
        ->callTableBulkAction('processSelected', [$first, $denied])
        ->assertNotified('1 of 2 job requests accepted')
        ->assertSee('Job queued.');

    expect(DB::table('jobs')->count())->toBe(2);
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    $browser->call('pollJobFeedback')
        ->assertTableBulkActionDisabled('processSelected')
        ->assertTableActionEnabled('process', $first)
        ->assertTableActionDisabled('process', $second)
        ->assertSee('Job completed.')
        ->assertSee('Job queued.')
        ->assertNotified("Processed {$first->name}");
    $anotherTab->call('pollJobFeedback')
        ->assertSee('Job completed.')
        ->assertSee('Job queued.')
        ->assertNotified("Processed {$first->name}");

    expect($first->refresh()->processed)->toBeTrue()
        ->and($denied->refresh()->processed)->toBeFalse()
        ->and($unselected->refresh()->processed)->toBeFalse();

    $second->update(['eligible' => false]);
    $browser->call('pollJobFeedback')
        ->assertDontSee('Job queued.')
        ->assertTableBulkActionEnabled('processSelected')
        ->assertDontSee('wire:poll.', escape: false);
});

it('explains when no selected record is eligible', function (): void {
    $ineligible = feedbackRecord('Ineligible', eligible: false);

    Livewire::test(JobTableHost::class)
        ->callTableBulkAction('processSelected', [$ineligible])
        ->assertNotified(Notification::make()
            ->title('0 of 1 job requests accepted')
            ->body('1 selected record is not authorized for this action.')
            ->warning())
        ->assertDontSee('wire:poll.', escape: false);

    expect(DB::table('jobs')->count())->toBe(0);
});

it('confirms a stale bulk replacement for the subscribed record only and rechecks authorization', function (): void {
    config(['filament-job-feedback.stale_grace_seconds' => 0]);
    $first = feedbackRecord('First');
    $second = feedbackRecord('Second');
    $browser = Livewire::test(JobTableHost::class)
        ->callTableBulkAction('processSelected', [$first, $second]);
    $subscriptions = collect($browser->get('jobFeedbackSubscriptions'))->where('action', 'processSelected');
    $staleKey = $subscriptions->where('record', (string) $first->id)->keys()->sole();
    $staleId = $subscriptions[$staleKey]['id'];
    app(CacheExecutionStore::class)->recordProcessing($staleId, 1, 1);

    $this->travel(1)->second();
    $browser->call('pollJobFeedback')->assertSee('Stale')
        ->call('mountStaleJobFeedbackReplacement', $staleKey)
        ->assertSet('mountedActions.0.name', 'processSelected');
    $first->update(['eligible' => false]);
    $browser->call('callMountedAction');
    expect(DB::table('jobs')->count())->toBe(2);

    $first->update(['eligible' => true]);
    $browser->call('unmountAction')
        ->callTableBulkAction('processSelected', [$first]);
    $browser->set('selectedTableRecords', [(string) $second->id])
        ->call('mountStaleJobFeedbackReplacement', $staleKey);
    $mounted = $browser->instance()->getMountedAction();
    expect($mounted->getSelectedRecords()->modelKeys())->toBe([$first->id])
        ->and($mounted->getSelectedRecordsQuery()->sole()->is($first))->toBeTrue();
    $browser->call('callMountedAction');

    $feedback = app(FilamentJobFeedback::class);
    expect(DB::table('jobs')->count())->toBe(3)
        ->and($feedback->active(FilamentJobFeedback::deduplicationKey(new ProcessFeedbackRecord($first->id)))?->id)->not->toBe($staleId)
        ->and($feedback->active(FilamentJobFeedback::deduplicationKey(new ProcessFeedbackRecord($second->id)))?->id)
        ->toBe($subscriptions->where('record', (string) $second->id)->sole()['id']);
});

it('offers recovery on the bulk trigger and restarts all its stale records, not the current selection', function (): void {
    $this->freezeTime();
    config(['filament-job-feedback.stale_grace_seconds' => 0]);
    $stale = [feedbackRecord('First'), feedbackRecord('Second')];
    $queued = feedbackRecord('Still queued');
    $browser = Livewire::test(JobTableHost::class)->callTableBulkAction('processSelected', [...$stale, $queued]);
    $subscriptions = collect($browser->get('jobFeedbackSubscriptions'))->where('action', 'processSelected');
    $staleSubscriptions = $subscriptions->whereIn('record', array_map(fn ($record) => (string) $record->id, $stale));
    foreach ($staleSubscriptions as $subscription) {
        app(CacheExecutionStore::class)->recordProcessing($subscription['id'], 1, 1);
    }

    $this->travel(1)->second();
    $browser->set('selectedTableRecords', [])
        ->call('pollJobFeedback')
        ->assertSeeHtml('data-job-feedback-stale="true"')
        ->call('mountStaleJobFeedbackReplacement', $staleSubscriptions->keys()->first())
        ->assertSet('mountedActions.0.name', 'processSelected');
    expect($browser->instance()->getMountedAction()->getSelectedRecords()->modelKeys())->toBe([$stale[0]->id, $stale[1]->id]);
    $browser->call('callMountedAction');

    expect(DB::table('jobs')->count())->toBe(5)
        ->and(collect($browser->get('jobFeedbackSubscriptions'))->where('action', 'processSelected'))->toHaveCount(5);
    $browser->assertTableBulkActionDisabled('processSelected');
});

it('reports selected records skipped by the queue condition', function (): void {
    $record = feedbackRecord('Eligible');

    JobBulkAction::configureUsing(
        fn (JobBulkAction $action): JobBulkAction => $action->queueIf(false),
        fn () => Livewire::test(JobTableHost::class)
            ->callTableBulkAction('processSelected', [$record])
            ->assertNotified(Notification::make()
                ->title('0 of 1 job requests accepted')
                ->body('1 selected record does not meet the conditions to queue a job.')
                ->warning()),
        isImportant: true,
    );

    expect(DB::table('jobs')->count())->toBe(0);
});

it('queues eligible bulk jobs after a panel-wide Filament transaction and reports skipped records', function (): void {
    config(['queue.connections.database.after_commit' => true]);
    Filament::setCurrentPanel(Panel::make()->id('feedback-tests')->databaseTransactions());
    Filament::getCurrentPanel()?->boot();
    $eligible = feedbackRecord('Eligible');
    $ineligible = feedbackRecord('Ineligible', eligible: false);

    JobBulkAction::configureUsing(
        fn (JobBulkAction $action): JobBulkAction => $action->after(function () use ($action): void {
            expect($action->hasDatabaseTransactions())->toBeTrue()
                ->and(DB::table('jobs')->count())->toBe(0);
        }),
        fn () => Livewire::test(JobTableHost::class)
            ->callTableBulkAction('processSelected', [$eligible, $ineligible])
            ->assertNotified('1 of 2 job requests accepted')
            ->assertTableBulkActionDisabled('processSelected')
            ->assertSee('Job queued.'),
        isImportant: true,
    );

    expect(DB::table('jobs')->count())->toBe(1);
});

it('shares a run between a record-bound action, table row, and bulk actions', function (): void {
    $document = feedbackRecord('Published article');
    $denied = feedbackRecord('Draft article', eligible: false);

    Livewire::test(JobActionHost::class, ['record' => $denied])
        ->assertActionHidden('process')
        ->call('mountAction', 'process')
        ->assertDontSee('Job queued.');
    expect(DB::table('jobs')->count())->toBe(0);

    $detail = Livewire::test(JobActionHost::class, ['record' => $document])
        ->callAction('process')
        ->assertActionDisabled('process')
        ->assertSee('wire:poll.3s="pollJobFeedback"', escape: false);

    $row = Livewire::test(JobTableHost::class)
        ->callTableAction('process', $document)
        ->assertSee('Job queued.');

    $list = Livewire::test(JobTableHost::class)
        ->callTableBulkAction('processSelected', [$document])
        ->assertSee('Job queued.');

    expect(DB::table('jobs')->count())->toBe(1);
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    foreach ([$row, $list] as $browser) {
        $browser->call('pollJobFeedback')
            ->assertSee('Job completed.')
            ->assertNotified("Processed {$document->name}");
    }

    $detail->call('pollJobFeedback')
        ->assertActionEnabled('process')
        ->assertDontSee('wire:poll.', escape: false)
        ->assertNotified("Processed {$document->name}");
});
