<?php

declare(strict_types=1);

use SkyRaptor\FilamentJobFeedback\Execution;
use SkyRaptor\FilamentJobFeedback\ExecutionStatus;

it('records the queue and retry lifecycle in delivery order', function (): void {
    $execution = (new Execution('run', null))
        ->transition(ExecutionStatus::Processing, 1)
        ->transition(ExecutionStatus::Retrying, 1)
        ->transition(ExecutionStatus::Processing, 2)
        ->transition(ExecutionStatus::Completed, 2);

    expect($execution->status)->toBe(ExecutionStatus::Completed)
        ->and($execution->history)->toBe([
            ['status' => ExecutionStatus::Queued, 'attempt' => 0],
            ['status' => ExecutionStatus::Processing, 'attempt' => 1],
            ['status' => ExecutionStatus::Retrying, 'attempt' => 1],
            ['status' => ExecutionStatus::Processing, 'attempt' => 2],
            ['status' => ExecutionStatus::Completed, 'attempt' => 2],
        ]);
});

it('does not add lifecycle events for invalid transitions', function (
    ExecutionStatus $current,
    int $currentAttempt,
    ExecutionStatus $incoming,
    int $incomingAttempt,
): void {
    $execution = new Execution('run', null, $current, $currentAttempt);
    $updated = $execution->transition($incoming, $incomingAttempt);

    expect($updated->status)->toBe($current)
        ->and($updated->attempt)->toBe($currentAttempt)
        ->and($updated->history)->toBe([]);
})->with([
    'stale failure' => [ExecutionStatus::Processing, 2, ExecutionStatus::Failed, 1],
    'duplicate processing' => [ExecutionStatus::Processing, 2, ExecutionStatus::Processing, 2],
    'completion before processing' => [ExecutionStatus::Queued, 0, ExecutionStatus::Completed, 1],
    'retry from another attempt' => [ExecutionStatus::Processing, 1, ExecutionStatus::Retrying, 2],
    'completed run cannot restart' => [ExecutionStatus::Completed, 1, ExecutionStatus::Processing, 2],
    'failed run cannot complete' => [ExecutionStatus::Failed, 1, ExecutionStatus::Completed, 1],
]);
