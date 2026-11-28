<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback\Actions;

interface HasSharedOperationFeedback
{
    public function hasSharedOperationSubscriptions(): bool;

    public function pollSharedOperations(): void;
}
