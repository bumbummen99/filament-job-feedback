<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use SkyRaptor\FilamentJobFeedback\FilamentJobFeedback;

final class UniqueUntilProcessingFeedbackJob extends UniqueFeedbackJob implements ShouldBeUniqueUntilProcessing
{
    public function handle(): void
    {
        if ($this->uniqueId() === 'first') {
            app(FilamentJobFeedback::class)->start(fn (): self => new self('first'));
        }
    }
}
