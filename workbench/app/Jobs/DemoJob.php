<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class DemoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        protected readonly int $seconds,
        protected readonly bool $retryThenFail = false,
    ) {}

    public function handle(): void
    {
        if ($this->seconds > 0) {
            sleep($this->seconds);
        }

        if (! $this->retryThenFail) {
            return;
        }

        if ($this->attempts() === 1) {
            $this->release($this->seconds);

            return;
        }

        $this->fail(new RuntimeException('Intentional failure for the Workbench demo.'));
    }
}
