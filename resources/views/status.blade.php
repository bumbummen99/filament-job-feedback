<div>
    @foreach ($this->getJobFeedbackStatusRows() as ['execution' => $execution, 'status' => $status])
        <div wire:key="job-feedback-{{ $execution->id }}">
            <p role="status" aria-live="polite">
                {{ __("filament-job-feedback::messages.status.{$status}") }}
            </p>
        </div>
    @endforeach
</div>
