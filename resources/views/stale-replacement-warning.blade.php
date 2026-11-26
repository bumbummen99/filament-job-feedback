@isset($eyebrow)
    <span style="display: block; margin-bottom: 0.5rem;">{{ $eyebrow }}</span>
@endisset

<span
    role="note"
    style="
        --stale-warning-foreground: light-dark(var(--warning-700, #92400e), var(--warning-300, #fcd34d));
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        margin-bottom: 0.5rem;
        padding: 0.75rem 1rem;
        border-radius: 0.5rem;
        border-inline-start: 3px solid var(--warning-500, #d97706);
        background-color: color-mix(in srgb, var(--warning-500, #d97706) 12%, transparent);
        font-size: 0.875rem;
        font-weight: normal;
        line-height: 1.5;
    "
>
    <x-filament::icon
        icon="heroicon-o-exclamation-triangle"
        aria-hidden="true"
        style="width: 1.25rem; height: 1.25rem; flex-shrink: 0; color: var(--stale-warning-foreground);"
    />
    <span>
        <strong style="color: var(--stale-warning-foreground);">{{ __('filament-job-feedback::messages.stale_replacement_heading') }}</strong>
        <span style="display: block;">{{ __('filament-job-feedback::messages.stale_replacement_warning') }}</span>
    </span>
</span>

@isset($heading)
    <span>{{ $heading }}</span>
@endisset
