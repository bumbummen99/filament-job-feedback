<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'discovery' => env('WORKBENCH_BROWSER_DISCOVERY', false),
    'recovery' => env('WORKBENCH_BROWSER_RECOVERY', false),
    'submission_slot' => env('WORKBENCH_BROWSER_SUBMISSION_SLOT', ''),
];
