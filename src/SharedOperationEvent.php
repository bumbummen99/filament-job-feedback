<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback;

final readonly class SharedOperationEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public int $cursor,
        public string $type,
        public array $data = [],
    ) {}
}
