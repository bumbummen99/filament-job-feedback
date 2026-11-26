<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use SkyRaptor\FilamentJobFeedback\Contracts\ShouldBeDeduplicated;

final class RebuildSearchIndex extends DemoJob implements ShouldBeDeduplicated
{
    public int $timeout = 60;
}
