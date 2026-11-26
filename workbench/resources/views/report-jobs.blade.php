<x-filament-panels::page>
    <x-filament::section
        heading="Independent runs"
        description="Each allowed form submission queues a separate run. Enable the retry option to see a job retry once and then fail."
    >
        {{ $this->reportAction }}
    </x-filament::section>

    <x-filament::section heading="Execution status">
        @include('filament-job-feedback::status')
    </x-filament::section>
</x-filament-panels::page>
