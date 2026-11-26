<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Contracts;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Optionally define deduplicationId(): string to distinguish runs of the same job class. */
interface ShouldBeDeduplicated extends ShouldQueue {}
