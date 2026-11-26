<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\InvalidPayloadException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use SkyRaptor\FilamentJobFeedback\CacheExecutionStore;
use SkyRaptor\FilamentJobFeedback\Contracts\ShouldBeDeduplicated;
use SkyRaptor\FilamentJobFeedback\ExecutionStatus;
use SkyRaptor\FilamentJobFeedback\FilamentJobFeedback;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\DeduplicatedFeedbackJob;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\FeedbackJob;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\UniqueFeedbackJob;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\UniqueUntilProcessingFeedbackJob;

final class DeduplicatedUntilProcessingFeedbackJob extends UniqueFeedbackJob implements ShouldBeDeduplicated, ShouldBeUniqueUntilProcessing
{
    public function handle(): void
    {
        app(FilamentJobFeedback::class)->start(fn (): self => new self);
    }
}

final class PayloadValidationFeedbackJob extends UniqueFeedbackJob implements ShouldBeDeduplicated
{
    public function __construct(private readonly bool $malformed)
    {
        parent::__construct();
    }

    public function displayName(): string
    {
        return $this->malformed ? "\xff" : self::class;
    }
}

it('joins an active feedback run even after Laravel releases an until-processing lock', function (): void {
    $this->useDatabaseQueueForFeedback();
    config(['cache.stores.unique-jobs' => ['driver' => 'array']]);
    DeduplicatedUntilProcessingFeedbackJob::dispatch();
    $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);

    $feedback = app(FilamentJobFeedback::class);
    expect($feedback->start(fn (): DeduplicatedUntilProcessingFeedbackJob => new DeduplicatedUntilProcessingFeedbackJob))->toBe($payload['uuid'])
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    expect($feedback->find($payload['uuid'])?->status)->toBe(ExecutionStatus::Completed)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('releases native uniqueness when saving a feedback claim fails before dispatch', function (): void {
    $this->useDatabaseQueueForFeedback();
    config(['cache.stores.unique-jobs' => ['driver' => 'array']]);

    $cache = Mockery::mock(Repository::class, [new ArrayStore])->makePartial();
    $cache->shouldReceive('forever')->once()->andReturnFalse();
    $feedback = new FilamentJobFeedback(new CacheExecutionStore($cache), app(Dispatcher::class));
    $job = fn (): UniqueFeedbackJob => new UniqueFeedbackJob;

    expect(fn () => $feedback->start($job))->toThrow(RuntimeException::class, 'Unable to save the job feedback execution.');
    expect(Context::hasHidden('laravel_unique_job_key'))->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0);
    expect(app(FilamentJobFeedback::class)->start($job))->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('joins a contract job dispatched normally without changing ordinary dispatch behavior', function (): void {
    $this->useDatabaseQueueForFeedback();
    $feedback = app(FilamentJobFeedback::class);

    Bus::dispatch(new DeduplicatedFeedbackJob('tenant:42'));
    Bus::dispatch(new DeduplicatedFeedbackJob('tenant:42'));
    $payload = json_decode((string) DB::table('jobs')->orderBy('id')->value('payload'), true, flags: JSON_THROW_ON_ERROR);

    expect($feedback->start(fn () => new DeduplicatedFeedbackJob('tenant:42')))->toBe($payload['uuid'])
        ->and(DB::table('jobs')->count())->toBe(2);

    expect($feedback->start(fn () => new DeduplicatedFeedbackJob('tenant:99')))->not->toBe($payload['uuid'])
        ->and(DB::table('jobs')->count())->toBe(3);
});

it('joins the same active execution without creating or dispatching a second job', function (): void {
    Queue::fake();

    $feedback = app(FilamentJobFeedback::class);
    $first = $feedback->start(fn (): ShouldQueue => new DeduplicatedFeedbackJob);
    $second = $feedback->start(fn (): ShouldQueue => new DeduplicatedFeedbackJob);

    expect($second)->toBe($first)
        ->and($feedback->active(deduplicatedFeedbackKey())?->id)->toBe($first)
        ->and($feedback->find($first)?->status)->toBe(ExecutionStatus::DispatchPending);

    Queue::assertPushed(DeduplicatedFeedbackJob::class, 1);
});

it('keeps independent runs isolated and releases a terminal claim', function (): void {
    config(['queue.default' => 'sync']);

    $feedback = app(FilamentJobFeedback::class);
    $first = $feedback->start(fn (): ShouldQueue => new FeedbackJob);
    $second = $feedback->start(fn (): ShouldQueue => new FeedbackJob);
    $shared = $feedback->start(fn (): ShouldQueue => new DeduplicatedFeedbackJob);

    expect($second)->not->toBe($first)
        ->and($feedback->find($first)?->status)->toBe(ExecutionStatus::Completed)
        ->and($feedback->find($second)?->status)->toBe(ExecutionStatus::Completed)
        ->and($feedback->active(deduplicatedFeedbackKey()))->toBeNull()
        ->and($feedback->start(fn (): ShouldQueue => new DeduplicatedFeedbackJob))->not->toBe($shared);
});

it('allows a new claim when job construction fails before dispatch', function (): void {
    Queue::fake();

    $feedback = app(FilamentJobFeedback::class);
    expect(fn (): string => $feedback->start(
        fn (): never => throw new LogicException('Factory failed.'),
    ))->toThrow(LogicException::class, 'Factory failed.');

    expect($feedback->active(deduplicatedFeedbackKey()))->toBeNull();
    $feedback->start(fn (): ShouldQueue => new DeduplicatedFeedbackJob);
    Queue::assertPushed(DeduplicatedFeedbackJob::class, 1);
});

it('retains the claim when a queue push has an unknown outcome', function (): void {
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue connection lost.'));

    $feedback = app(FilamentJobFeedback::class);
    expect(fn (): ?string => $feedback->start(fn (): ShouldQueue => new DeduplicatedFeedbackJob))
        ->toThrow(RuntimeException::class, 'Queue connection lost.');

    $active = $feedback->active(deduplicatedFeedbackKey());
    expect($active?->status)->toBe(ExecutionStatus::DispatchUnknown)
        ->and($feedback->start(fn (): ShouldQueue => new DeduplicatedFeedbackJob))->toBe($active?->id);
});

it('clears the feedback and native unique claims when Laravel rejects the payload before enqueue', function (): void {
    $this->useDatabaseQueueForFeedback();
    config(['cache.stores.unique-jobs' => ['driver' => 'array']]);
    $feedback = app(FilamentJobFeedback::class);
    $invalid = new PayloadValidationFeedbackJob(malformed: true);
    $key = FilamentJobFeedback::deduplicationKey($invalid);
    assert(is_string($key));

    expect(fn () => $feedback->start(fn () => $invalid))->toThrow(InvalidPayloadException::class)
        ->and($feedback->active($key))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($feedback->start(fn () => new PayloadValidationFeedbackJob(malformed: false)))->toBeString()
        ->and(DB::table('jobs')->count())->toBe(1);
});

function deduplicatedFeedbackKey(): string
{
    return FilamentJobFeedback::deduplicationKey(new DeduplicatedFeedbackJob) ?? throw new LogicException('Missing job identity.');
}

it('tracks an ordinary dispatch by its Laravel UUID without registration', function (): void {
    $this->useDatabaseQueueForFeedback();
    Bus::dispatch(new FeedbackJob);
    $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);

    $feedback = app(FilamentJobFeedback::class);
    expect($feedback->find($payload['uuid'])?->status)->toBe(ExecutionStatus::Queued);

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    expect($feedback->find($payload['uuid'])?->status)->toBe(ExecutionStatus::Completed);
});

it('can disable external tracking without disabling action feedback', function (): void {
    $this->useDatabaseQueueForFeedback();
    config(['filament-job-feedback.track_external_jobs' => false]);

    $feedback = app(FilamentJobFeedback::class);
    Bus::dispatch(new DeduplicatedFeedbackJob);
    $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);

    expect($feedback->find($payload['uuid']))->toBeNull()
        ->and($feedback->active(deduplicatedFeedbackKey()))->toBeNull();

    $id = $feedback->start(fn (): DeduplicatedFeedbackJob => new DeduplicatedFeedbackJob);

    expect($feedback->find($id)?->status)->toBe(ExecutionStatus::Queued);
});

it('does not index an external after-commit dispatch when its transaction rolls back', function (): void {
    $this->useDatabaseQueueForFeedback();
    $feedback = app(FilamentJobFeedback::class);

    DB::beginTransaction();
    Bus::dispatch((new DeduplicatedFeedbackJob)->afterCommit());
    expect($feedback->active(deduplicatedFeedbackKey()))->toBeNull();
    DB::rollBack();

    expect($feedback->active(deduplicatedFeedbackKey()))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('discards the database queue claim and native unique lock after rolling back a committed savepoint', function (): void {
    $this->useDatabaseQueueForFeedback();
    config(['cache.stores.unique-jobs' => ['driver' => 'array']]);
    $feedback = app(FilamentJobFeedback::class);
    $start = fn (): ?string => $feedback->start(fn () => new DeduplicatedUntilProcessingFeedbackJob);

    DB::beginTransaction();

    try {
        DB::beginTransaction();
        $id = $start();
        DB::commit();

        expect($id)->toBeString()
            ->and($feedback->find($id)?->status)->toBe(ExecutionStatus::Queued)
            ->and(DB::table('jobs')->count())->toBe(1);
    } finally {
        DB::rollBack(0);
    }

    expect($feedback->find($id))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($start())->toBeString()->not->toBe($id);
});

it('joins ordinary dispatches without merging their lifecycle histories', function (): void {
    $this->useDatabaseQueueForFeedback();
    $feedback = app(FilamentJobFeedback::class);
    Bus::dispatch(new DeduplicatedFeedbackJob);
    Bus::dispatch(new DeduplicatedFeedbackJob);
    $ids = DB::table('jobs')->orderBy('id')->pluck('payload')
        ->map(fn (string $payload): string => json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['uuid']);

    $join = fn (): ?string => $feedback->start(fn (): DeduplicatedFeedbackJob => new DeduplicatedFeedbackJob);

    expect($join())->toBe($ids[0])
        ->and(DB::table('jobs')->count())->toBe(2);
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    expect($feedback->find($ids[0])?->status)->toBe(ExecutionStatus::Completed)
        ->and($feedback->find($ids[1])?->status)->toBe(ExecutionStatus::Queued)
        ->and($join())->toBe($ids[1]);
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    expect($feedback->find($ids[1])?->status)->toBe(ExecutionStatus::Completed)
        ->and($feedback->active(deduplicatedFeedbackKey()))->toBeNull();
});

it('uses Laravel uniqueness without attaching jobs that do not implement the feedback contract', function (): void {
    $this->useDatabaseQueueForFeedback();
    config(['cache.stores.unique-jobs' => ['driver' => 'array']]);

    $feedback = app(FilamentJobFeedback::class);
    $first = $feedback->start(fn (): UniqueFeedbackJob => new UniqueFeedbackJob);

    expect($first)->toBeString()
        ->and($feedback->start(fn (): UniqueFeedbackJob => new UniqueFeedbackJob))->toBeNull()
        ->and($feedback->start(fn (): UniqueFeedbackJob => new UniqueFeedbackJob('distinct')))->toBeString()
        ->and(DB::table('jobs')->count())->toBe(2);

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();
    expect($feedback->find($first)?->status)->toBe(ExecutionStatus::Completed)
        ->and($feedback->start(fn (): UniqueFeedbackJob => new UniqueFeedbackJob))->toBeString()
        ->and(DB::table('jobs')->count())->toBe(2);
});

it('honors an external Laravel lock without joining the external job and respects its expiry', function (): void {
    $this->useDatabaseQueueForFeedback();
    config(['cache.stores.unique-jobs' => ['driver' => 'array']]);
    UniqueFeedbackJob::dispatch();

    $feedback = app(FilamentJobFeedback::class);
    expect($feedback->start(fn (): UniqueFeedbackJob => new UniqueFeedbackJob))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->travel(61)->seconds();

    expect($feedback->start(fn (): UniqueFeedbackJob => new UniqueFeedbackJob))->toBeString()
        ->and(DB::table('jobs')->count())->toBe(2);
});

it('keeps native uniqueness when a push may already have reached the queue', function (): void {
    config(['cache.stores.unique-jobs' => ['driver' => 'array']]);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue connection lost.'));

    $feedback = app(FilamentJobFeedback::class);
    expect(fn (): ?string => $feedback->start(fn (): UniqueFeedbackJob => new UniqueFeedbackJob))
        ->toThrow(RuntimeException::class, 'Queue connection lost.');

    expect($feedback->start(fn (): UniqueFeedbackJob => new UniqueFeedbackJob))->toBeNull();
});

it('lets Laravel release the until-processing lock before the job handler runs', function (): void {
    $this->useDatabaseQueueForFeedback();
    config(['cache.stores.unique-jobs' => ['driver' => 'array']]);

    $feedback = app(FilamentJobFeedback::class);
    $first = $feedback->start(fn (): UniqueUntilProcessingFeedbackJob => new UniqueUntilProcessingFeedbackJob('first'));
    expect($first)->toBeString()
        ->and($feedback->start(fn (): UniqueUntilProcessingFeedbackJob => new UniqueUntilProcessingFeedbackJob('first')))->toBeNull();

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    expect($feedback->find($first)?->status)->toBe(ExecutionStatus::Completed)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('keeps the active claim through a release and frees it after terminal failure', function (): void {
    $this->useDatabaseQueueForFeedback();
    $feedback = app(FilamentJobFeedback::class);
    $id = $feedback->start(fn (): ShouldQueue => new DeduplicatedFeedbackJob(releaseThenFail: true));

    $this->artisan('queue:work', ['--once' => true, '--tries' => 2])->assertSuccessful();
    expect($feedback->active(deduplicatedFeedbackKey())?->status)->toBe(ExecutionStatus::Retrying)
        ->and($feedback->start(fn (): ShouldQueue => new DeduplicatedFeedbackJob))->toBe($id);

    config(['queue.failed.driver' => 'null']);
    $this->artisan('queue:work', ['--once' => true, '--tries' => 2])->assertSuccessful();

    expect($feedback->active(deduplicatedFeedbackKey()))->toBeNull()
        ->and(array_column($feedback->find($id)?->history ?? [], 'status'))->toBe([
            ExecutionStatus::Queued,
            ExecutionStatus::Processing,
            ExecutionStatus::Retrying,
            ExecutionStatus::Processing,
            ExecutionStatus::Failed,
        ]);
});

it('keeps a timed-out attempt on the same run until Laravel retries it', function (): void {
    $this->useDatabaseQueueForFeedback();
    $feedback = app(FilamentJobFeedback::class);
    $id = $feedback->start(fn () => new DeduplicatedFeedbackJob);
    assert(is_string($id));

    $reserved = Queue::connection('database')->pop();
    assert($reserved !== null);
    event(new JobProcessing('database', $reserved));
    event(new JobTimedOut('database', $reserved));

    expect($feedback->find($id)?->status)->toBe(ExecutionStatus::TimedOut)
        ->and($feedback->start(fn () => new DeduplicatedFeedbackJob))->toBe($id)
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->travel(1)->hour();
    $this->artisan('queue:work', ['--once' => true, '--tries' => 2])->assertSuccessful();

    expect($feedback->find($id)?->status)->toBe(ExecutionStatus::Completed)
        ->and($feedback->find($id)?->attempt)->toBe(2)
        ->and($feedback->active(deduplicatedFeedbackKey()))->toBeNull();
});

it('does not reopen a terminal failure when Laravel reports the timeout afterward', function (): void {
    $this->useDatabaseQueueForFeedback();
    $feedback = app(FilamentJobFeedback::class);
    $id = $feedback->start(fn () => new DeduplicatedFeedbackJob);
    assert(is_string($id));

    $reserved = Queue::connection('database')->pop();
    assert($reserved !== null);
    event(new JobProcessing('database', $reserved));
    $reserved->fail(new RuntimeException('Worker timeout.'));
    event(new JobTimedOut('database', $reserved));

    expect($feedback->find($id)?->status)->toBe(ExecutionStatus::Failed)
        ->and($feedback->active(deduplicatedFeedbackKey()))->toBeNull()
        ->and(array_column($feedback->find($id)?->history ?? [], 'status'))->toBe([
            ExecutionStatus::Queued,
            ExecutionStatus::Processing,
            ExecutionStatus::Failed,
        ]);
});
