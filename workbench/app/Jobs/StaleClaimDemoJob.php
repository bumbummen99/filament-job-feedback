<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Foundation\Queue\Queueable;
use SkyRaptor\FilamentJobFeedback\Contracts\ShouldBeDeduplicated;

final class StaleClaimDemoJob implements ShouldBeDeduplicated
{
    use Queueable;

    public const string SilentConnection = 'workbench-silent-completion';

    public const int Timeout = 1;

    public int $timeout = self::Timeout;

    public function __construct(public readonly ?int $documentId = null) {}

    public function deduplicationId(): string
    {
        return $this->documentId === null ? 'global' : "document:{$this->documentId}";
    }

    public function handle(): void {}
}
