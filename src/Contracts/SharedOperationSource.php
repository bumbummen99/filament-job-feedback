<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Contracts;

use SkyRaptor\FilamentJobFeedback\SharedOperationSnapshot;

interface SharedOperationSource
{
    public function canFollow(string $operation): bool;

    public function cursor(string $operation): int;

    public function read(string $operation, int $after): SharedOperationSnapshot;
}
