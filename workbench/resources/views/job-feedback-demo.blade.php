<x-filament-panels::page>
    <x-filament::section
        heading="One shared run"
        description="Open two tabs to follow the same active rebuild. Clicking again joins the run."
    >
        {{ $this->rebuildAction }}
    </x-filament::section>

    @can('run-workbench-jobs')
        <x-filament::section heading="Stale claim recovery">
            <p>
                Simulate a job whose completion update is lost. No worker is needed.
                After about {{ $this->getStaleClaimDemoWaitSeconds() }} seconds, click the <strong>Simulate a lost update</strong> button again when it shows the <strong>Stale</strong> badge.
                Turn off <strong>Drop the completion update</strong> in the replacement form to finish the demo.
            </p>

            {{ $this->simulateStaleClaimAction }}
        </x-filament::section>
    @endcan

    <x-filament::section heading="Execution status" description="Queued and running jobs appear here.">
        @include('filament-job-feedback::status')
    </x-filament::section>

    @can('run-workbench-jobs')
        <x-filament::section heading="Shared-run callbacks" description="Each browser tab receives its own callbacks.">
            @if ($sharedEvents === [])
                <p>No callbacks yet.</p>
            @else
                <ol class="flex flex-wrap gap-2">
                    @foreach ($sharedEvents as $event)
                        <li>
                            <x-filament::badge :color="$event === 'completed' ? 'success' : 'gray'">
                                {{ __("filament-job-feedback::messages.status.{$event}") }}
                            </x-filament::badge>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-filament::section>
    @endcan
</x-filament-panels::page>
