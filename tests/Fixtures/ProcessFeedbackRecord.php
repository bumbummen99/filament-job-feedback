<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Tests\Fixtures;

use SkyRaptor\FilamentJobFeedback\Contracts\ShouldBeDeduplicated;

final class ProcessFeedbackRecord implements ShouldBeDeduplicated
{
    public function __construct(private readonly int $recordId) {}

    public function deduplicationId(): string
    {
        return (string) $this->recordId;
    }

    public function handle(): void
    {
        FeedbackRecord::query()->findOrFail($this->recordId)->update(['processed' => true]);
    }
}
