<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Actions;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use LogicException;
use SkyRaptor\FilamentJobFeedback\Contracts\SharedOperationSource;
use SkyRaptor\FilamentJobFeedback\SharedOperationEvent;
use SkyRaptor\FilamentJobFeedback\SharedOperationSnapshot;

trait InteractsWithSharedOperationFeedback
{
    /** @var array<string, array{operation: string, cursor: int, owner: int|string|null}> */
    #[Locked]
    public array $sharedOperationSubscriptions = [];

    abstract protected function sharedOperationSource(): SharedOperationSource;

    abstract protected function onSharedOperationEvent(string $operation, SharedOperationEvent $event): void;

    abstract protected function onSharedOperationState(string $operation, SharedOperationSnapshot $snapshot): bool;

    public function hasSharedOperationSubscriptions(): bool
    {
        return $this->sharedOperationSubscriptions !== [];
    }

    public function subscribeToSharedOperation(string $operation): void
    {
        $source = $this->sharedOperationSource();
        abort_unless($source->canFollow($operation), 403);
        $key = hash('sha256', $operation);

        if (isset($this->sharedOperationSubscriptions[$key])
            && $this->sharedOperationSubscriptions[$key]['owner'] === Auth::id()) {
            return;
        }
        $cursor = $source->cursor($operation);

        if ($cursor < 0) {
            throw new LogicException('A shared operation cursor must not be negative.');
        }
        $this->sharedOperationSubscriptions[$key] = [
            'operation' => $operation,
            'cursor' => $cursor,
            'owner' => Auth::id(),
        ];
    }

    public function pollSharedOperations(): void
    {
        $source = $this->sharedOperationSource();
        foreach ($this->sharedOperationSubscriptions as $key => $subscription) {
            $operation = $subscription['operation'];

            if ($subscription['owner'] !== Auth::id() || ! $source->canFollow($operation)) {
                unset($this->sharedOperationSubscriptions[$key]);

                continue;
            }
            $snapshot = $source->read($operation, $subscription['cursor']);
            $cursor = $subscription['cursor'];
            foreach ($snapshot->events as $event) {
                if ($event->cursor <= $cursor) {
                    throw new LogicException('Shared operation events must have strictly increasing cursors.');
                }
                $this->onSharedOperationEvent($operation, $event);
                $cursor = $event->cursor;
                $this->sharedOperationSubscriptions[$key] = [
                    ...$subscription,
                    'cursor' => $cursor,
                ];
            }

            if ($this->onSharedOperationState($operation, $snapshot)) {
                unset($this->sharedOperationSubscriptions[$key]);
            }
        }
    }
}
