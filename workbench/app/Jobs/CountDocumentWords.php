<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Foundation\Queue\Queueable;
use SkyRaptor\FilamentJobFeedback\Contracts\ShouldBeDeduplicated;
use Workbench\App\Models\Document;

final class CountDocumentWords implements ShouldBeDeduplicated
{
    use Queueable;

    public function __construct(private readonly int $documentId, private readonly int $seconds) {}

    public function deduplicationId(): string
    {
        return (string) $this->documentId;
    }

    public function handle(): void
    {
        if ($this->seconds > 0) {
            sleep($this->seconds);
        }

        $document = Document::query()->findOrFail($this->documentId);
        $document->forceFill(['word_count' => str_word_count($document->body)])->save();
    }
}
