<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Tests\Fixtures;

use SkyRaptor\FilamentJobFeedback\Contracts\ShouldBeDeduplicated;

final class DeduplicatedFeedbackJob extends FeedbackJob implements ShouldBeDeduplicated
{
    public function __construct(private readonly string $id = 'example', bool $releaseThenFail = false)
    {
        parent::__construct($releaseThenFail);
    }

    public function deduplicationId(): string
    {
        return $this->id;
    }
}
