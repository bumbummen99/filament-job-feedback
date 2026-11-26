<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

class UniqueFeedbackJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(private readonly string $identity = 'example') {}

    public function uniqueId(): string
    {
        return $this->identity;
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store('unique-jobs');
    }

    public function handle(): void {}
}
