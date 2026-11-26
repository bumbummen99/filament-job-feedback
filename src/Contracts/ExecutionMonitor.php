<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Contracts;

use SkyRaptor\FilamentJobFeedback\ExecutionStatus;

interface ExecutionMonitor
{
    public function record(string $id, ExecutionStatus $status, int $attempt = 0): void;
}
