<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

class FeedbackJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(private readonly bool $releaseThenFail = false) {}

    public function handle(): void
    {
        if (! $this->releaseThenFail) {
            return;
        }

        if ($this->attempts() === 1) {
            $this->release();

            return;
        }

        $this->fail(new RuntimeException('Terminal failure.'));
    }
}
