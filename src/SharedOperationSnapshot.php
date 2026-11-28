<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback;

final readonly class SharedOperationSnapshot
{
    /** @param list<SharedOperationEvent> $events */
    public function __construct(
        public string $state,
        public array $events = [],
    ) {}
}
