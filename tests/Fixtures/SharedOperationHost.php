<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Tests\Fixtures;

use Livewire\Component;
use SkyRaptor\FilamentJobFeedback\Actions\HasSharedOperationFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\InteractsWithSharedOperationFeedback;
use SkyRaptor\FilamentJobFeedback\Contracts\SharedOperationSource;
use SkyRaptor\FilamentJobFeedback\SharedOperationEvent;
use SkyRaptor\FilamentJobFeedback\SharedOperationSnapshot;

class SharedOperationHost extends Component implements HasSharedOperationFeedback
{
    use InteractsWithSharedOperationFeedback;

    /** @var list<string> */
    public array $received = [];

    protected function sharedOperationSource(): SharedOperationSource
    {
        return app(SharedOperationSource::class);
    }

    protected function onSharedOperationEvent(string $operation, SharedOperationEvent $event): void
    {
        $this->received[] = $operation.':'.$event->type;
    }

    protected function onSharedOperationState(string $operation, SharedOperationSnapshot $snapshot): bool
    {
        if ($snapshot->state !== 'done') {
            return false;
        }
        $this->received[] = $operation.':done';

        return true;
    }

    public function render(): string
    {
        return '<div>Feedback</div>';
    }
}
