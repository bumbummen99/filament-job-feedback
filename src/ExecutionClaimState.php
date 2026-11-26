<?php

declare(strict_types=1);

namespace SkyRaptor\FilamentJobFeedback;

enum ExecutionClaimState
{
    case Unknown;
    case Active;
    case Terminal;
}
