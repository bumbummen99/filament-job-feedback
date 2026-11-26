---
name: filament-job-feedback-development
description: >
  Configure and apply the Filament Job Feedback package in Laravel applications.
license: AGPL-3.0-only
metadata:
  author: Patrick Henninger
---

# Filament Job Feedback

Use this skill when a Laravel application needs to integrate the Filament Job Feedback package.

## Primary Goal

- use `skyraptor/filament-job-feedback` to dispatch Laravel jobs from Filament actions and display their lifecycle status

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel project
- inspect the target code paths where the package should be applied

### 2. Configure and attach a job action

- On a Filament 4 or 5 page or Livewire component with Filament actions, implement `Actions\HasJobFeedback` and use `Actions\InteractsWithJobFeedback` from `SkyRaptor\FilamentJobFeedback`.
- Define a named `Actions\JobAction` with `job()` providing a Laravel `ShouldQueue` job. There is no separate action list or polling markup. Actions share one poller per Livewire host and show their own disabled state and spinner; use `filament-job-feedback::status` for optional lifecycle text.
- Use `pollingInterval('750ms')` to override the default `3s`. Idle polling is off by default; enable `discoverSharedJobs()` on a keyed action to discover shared runs in already-open tabs.
- Use Filament's `authorize()` and confirmation. Lifecycle callbacks such as `onJobCompleted()` can refresh individual form fields, send notifications, or dispatch a Livewire event for another component.
- `onJobRetrying()` also receives `ExecutionStatus::TimedOut` for a worker-reported, non-terminal timeout. The run stays active while Laravel handles retries; terminal timeouts use `onJobFailed()`.
- Ensure queue workers and web requests use the same lock-capable cache store. Override it with `FILAMENT_JOB_FEEDBACK_CACHE_STORE` if needed; the job keeps its existing Laravel queue connection.
- Use an asynchronous queue connection and run a worker listening on the job's connection and queue. No package resources need to be published for the basic setup.
- For jobs that depend on data written in a Filament transaction, use Laravel's `afterCommit()` or the queue connection's `after_commit` option. Filament's `databaseTransaction()` alone does not defer queue pushes.
- Implement `Contracts\ShouldBeDeduplicated` on a job to let feedback actions join active runs by exact class, including observed ordinary dispatches. Add `public function deduplicationId(): string` when tenants or records represent different work.
- Ordinary dispatches ignore the feedback contract. Laravel's `ShouldBeUnique` and `ShouldBeUniqueUntilProcessing` still control native uniqueness and can be used together with the feedback contract. Set `track_external_jobs` to `false` to disable observation of ordinary dispatches.
- For deduplicated jobs safe to replace, add `allowStaleJobReplacement()`. The original trigger shows a **Stale** badge with a warning icon without changing its label or spinner. Clicking it reopens its modal with a **Replacing a stale run** callout above the modal title, using the theme’s warning color for its icon and heading. **Keep waiting** dismisses the modal without replacing the run, even during a background poll. Bulk recovery considers only its tracked stale records. Set `stale_fallback_timeout_seconds` if jobs do not declare a timeout; `stale_grace_seconds` defaults to 30. A replacement does not cancel the old job.
- Bind `Contracts\ExecutionReconciler` only when an integration can verify the state of an exact execution. Return `ExecutionClaimState::Terminal` only when it cannot still run or retry; missing evidence is `Unknown`.

- When an attempt qualifies for the **Stale** badge, a page already tracking the run shows a warning toast once. A bulk action shows one warning while it stays stale and one acceptance summary per submission or replacement. Reloading into an already-stale run does not replay the toast. Staleness does not mean failure.

## Rules, References, and Templates

Read before executing:

- See the README quick start for a complete page and Blade example, and its deduplication comparison to distinguish lifecycle tracking, feedback joins, and Laravel uniqueness.

## Examples

- For a global admin button, pass a `ShouldBeDeduplicated` job object to `job()` and authorize the action. For a factory that only uses server-side context, use `job($factory, discover: true)` to enable discovery before submission.
- For an Eloquent table, register `Actions\JobBulkAction` in `toolbarActions()`; no separate action list is needed. Use `authorizeIndividualRecords()` and define `job()` with the selected `$record`. Ineligible records are reported in a submission summary. Selection is retained by default; use Filament's `deselectRecordsAfterCompletion()` to clear it on submission.
- On a resource detail page, register `Actions\JobAction` in `getHeaderActions()`, bind the page record with `record()`, and authorize the operation for it. To share work with the list page's bulk action, use the same per-record key on both.
- For a table row, register `Actions\JobAction` in `recordActions()`. Filament supplies the row record; include it in the key when only matching rows should share a job.

## Anti-patterns

- Do not confuse the logical key with authorization or omit a tenant identifier from a tenant-specific key.
- Do not opt into render-time discovery for a job factory that requires submitted modal data or has side effects.
- Put bulk feedback in the bulk action's callbacks. Row actions still display per-row status. A row action that only discovers the run does not duplicate the bulk callbacks. When the user submits a replacement, the submitting action delivers its callbacks on that page; other table actions following the replacement still reflect its status.
- Do not use the in-memory `array` cache across processes or configure lifecycle callbacks as durable job side effects.
