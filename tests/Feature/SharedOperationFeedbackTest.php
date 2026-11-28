<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Livewire\Livewire;
use SkyRaptor\FilamentJobFeedback\Contracts\SharedOperationSource;
use SkyRaptor\FilamentJobFeedback\SharedOperationEvent;
use SkyRaptor\FilamentJobFeedback\SharedOperationSnapshot;
use SkyRaptor\FilamentJobFeedback\Tests\Fixtures\SharedOperationHost;

beforeEach(function (): void {
    $this->source = new class implements SharedOperationSource
    {
        public bool $allowed = true;

        public array $events = [];

        public array $states = [];

        public function canFollow(string $operation): bool
        {
            return $this->allowed;
        }

        public function cursor(string $operation): int
        {
            return count($this->events[$operation] ?? []);
        }

        public function read(string $operation, int $after): SharedOperationSnapshot
        {
            return new SharedOperationSnapshot(
                $this->states[$operation] ?? 'pending',
                array_values(array_filter($this->events[$operation] ?? [], fn (SharedOperationEvent $event): bool => $event->cursor > $after)),
            );
        }
    };
    $this->app->instance(SharedOperationSource::class, $this->source);
    $this->actingAs(new GenericUser(['id' => 1]));
});

it('keeps independent cursors for operations and browser tabs', function (): void {
    $first = Livewire::test(SharedOperationHost::class)
        ->call('subscribeToSharedOperation', 'a')
        ->call('subscribeToSharedOperation', 'b')
        ->assertSee('wire:poll.2s="pollSharedOperations"', escape: false);
    $this->source->events['a'] = [new SharedOperationEvent(1, 'failed')];
    $first->call('subscribeToSharedOperation', 'a');
    $second = Livewire::test(SharedOperationHost::class)->call('subscribeToSharedOperation', 'a');
    $this->source->events['a'][] = new SharedOperationEvent(2, 'recovery');
    $this->source->events['b'] = [new SharedOperationEvent(1, 'failed')];
    $this->source->states['a'] = 'done';
    $first->call('pollSharedOperations')->assertSet('received', ['a:failed', 'a:recovery', 'a:done', 'b:failed'])
        ->call('pollSharedOperations')->assertSet('received', ['a:failed', 'a:recovery', 'a:done', 'b:failed']);
    $second->call('pollSharedOperations')->assertSet('received', ['a:recovery', 'a:done'])
        ->assertDontSee('wire:poll.2s="pollSharedOperations"', escape: false);
});

it('reauthorizes and stops subscriptions after access or user changes', function (): void {
    $browser = Livewire::test(SharedOperationHost::class)->call('subscribeToSharedOperation', 'a');
    $this->source->allowed = false;
    $browser->call('pollSharedOperations')->assertSet('received', [])->assertSet('sharedOperationSubscriptions', []);
    $this->source->allowed = true;
    $browser->call('subscribeToSharedOperation', 'a');
    $this->actingAs(new GenericUser(['id' => 2]));
    $browser->call('pollSharedOperations')->assertSet('received', [])->assertSet('sharedOperationSubscriptions', []);
});

it('rejects unauthorized subscriptions', function (): void {
    $this->source->allowed = false;
    Livewire::test(SharedOperationHost::class)->call('subscribeToSharedOperation', 'a')->assertForbidden();
});
