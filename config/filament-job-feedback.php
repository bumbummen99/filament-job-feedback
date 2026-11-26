<?php

declare(strict_types=1);

return [
    'cache_store' => env('FILAMENT_JOB_FEEDBACK_CACHE_STORE'),
    'polling_interval' => '3s',
    'discover_shared_jobs' => false,
    'track_external_jobs' => true,
    'stale_grace_seconds' => 30,
    'stale_fallback_timeout_seconds' => null,
];
