<?php

declare(strict_types=1);

return [
    'placeholder' => 'FilamentJobFeedback placeholder translation.',
    'unique_job_blocked' => 'Job not queued because a matching job already holds its unique lock.',
    'unique_jobs_blocked' => '{1} One job was not queued because a matching job already holds its unique lock.|[2,*] :count jobs were not queued because matching jobs already hold their unique locks.',
    'bulk_requests_accepted' => ':accepted of :total job requests accepted',
    'bulk_replacements_blocked' => '{1} 1 replacement was blocked. The original run may have changed, or a matching job may still hold its unique lock.|[2,*] :count replacements were blocked. The original runs may have changed, or matching jobs may still hold their unique locks.',
    'bulk_records_denied' => '{1} 1 selected record is not authorized for this action.|[2,*] :count selected records are not authorized for this action.',
    'bulk_records_ineligible' => '{1} 1 selected record does not meet the conditions to queue a job.|[2,*] :count selected records do not meet the conditions to queue a job.',
    'start_new_run' => 'Start a new run',
    'stale_badge' => 'Stale',
    'stale_notification_title' => 'Job run may be stale',
    'stale_bulk_notification_title' => 'Bulk job runs may be stale',
    'stale_bulk_notification_body' => 'One or more jobs have not reported a recent update and may still run or retry. Use the bulk action marked Stale to review your options.',
    'stale_notification_body' => 'The job has not reported a recent update and may still run or retry. Use the action marked Stale to review your options.',
    'stale_action_description' => 'No recent job update. Activate this action to review starting a new run.',
    'keep_waiting' => 'Keep waiting',
    'stale_replacement_heading' => 'Replacing a stale run',
    'stale_replacement_warning' => 'Starting a new run does not cancel the original job. It may still be running or may be retried.',
    'stale_replacement_blocked' => 'No new run was started. The original run may have changed, or a matching job still holds its unique lock.',
    'status' => [
        'dispatch_pending' => 'Waiting for the queue to confirm dispatch.',
        'dispatch_unknown' => 'Dispatch could not be confirmed.',
        'queued' => 'Job queued.',
        'processing' => 'Job running.',
        'retrying' => 'Job waiting to retry.',
        'timed_out' => 'Job timed out; waiting for retry.',
        'completed' => 'Job completed.',
        'failed' => 'Job failed.',
        'discarded' => 'Job discarded.',
        'stale' => 'No recent update. The original job may still run or retry.',
        'inactive_unknown' => 'No longer the active run. Outcome unknown.',
    ],
];
