<div align="center">
    <h1>Filament Job Feedback</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/skyraptor/filament-job-feedback"><img src="https://img.shields.io/packagist/v/skyraptor/filament-job-feedback.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/skyraptor/filament-job-feedback"><img src="https://img.shields.io/packagist/php-v/skyraptor/filament-job-feedback.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/skyraptor/filament-job-feedback"><img src="https://badge.laravel.cloud/badge/skyraptor/filament-job-feedback?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/skyraptor/filament-job-feedback/actions"><img alt="GitHub Workflow Status (master)" src="https://img.shields.io/github/actions/workflow/status/skyraptor/filament-job-feedback/tests.yml?branch=master&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/skyraptor/filament-job-feedback"><img src="https://img.shields.io/packagist/dt/skyraptor/filament-job-feedback.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Track queued jobs from a native action on Filament 4 or 5.

## Quick start

### 1. Install the package

```bash
composer require skyraptor/filament-job-feedback
```

### 2. Use a shared cache

Web requests and queue workers must share a Laravel cache store that supports atomic locks and retains active entries. By default, the package uses the application's default cache store. To use a separate store, set `FILAMENT_JOB_FEEDBACK_CACHE_STORE` to a store name from `config/cache.php`.

The cache and the queue are independent: the package adds no tables and uses the job's configured Laravel queue connection. Do not use the `array` cache across processes, or flush or evict active entries; losing tracking state also loses the deduplication claim.

### 3. Add a job action

On a Filament page or Livewire component that already hosts Filament actions, implement `HasJobFeedback` and use `InteractsWithJobFeedback`. Define your named actions through Filament as usual; no separate action list is needed. This example assumes you already have a Laravel `ShouldQueue` job named `RebuildSearchIndex` and a `rebuildSearchIndex` authorization ability:

```php
use App\Jobs\RebuildSearchIndex;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use SkyRaptor\FilamentJobFeedback\Actions\HasJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\InteractsWithJobFeedback;
use SkyRaptor\FilamentJobFeedback\Actions\JobAction;

class SearchMaintenance extends Page implements HasJobFeedback
{
    use InteractsWithJobFeedback;

    protected string $view = 'filament.pages.search-maintenance';

    public function rebuildAction(): JobAction
    {
        return JobAction::make('rebuild')
            ->label('Rebuild search index')
            ->authorize('rebuildSearchIndex')
            ->requiresConfirmation()
            ->job(new RebuildSearchIndex)
            ->onJobCompleted(fn () => Notification::make()
                ->title('Search index rebuilt')
                ->success()
                ->send());
    }
}
```

In the page's Blade view:

```blade
<x-filament-panels::page>
    {{ $this->rebuildAction }}
</x-filament-panels::page>
```

### 4. Run a queue worker

Use an asynchronous Laravel queue connection. If you don't already have a worker listening on the job's connection and queue, start one. For a job using the application's default connection and queue:

```bash
php artisan queue:work
```

Open the page and run **Rebuild search index**. The action shows a spinner while the job is pending and sends a notification when it completes. No package resources need to be published for this example.

## Shared operation feedback

For work whose lifecycle is managed by another service, implement
`Contracts\SharedOperationSource`. Its `canFollow()` must authorize the
current user for the specified operation, `cursor()` returns the latest event
cursor, and `read()` returns a `SharedOperationSnapshot` with its state and
strictly increasing `SharedOperationEvent` cursors after the supplied cursor.
When reporting a terminal state, a source must include all events committed
before that state, using a consistent read or an equivalent ordering.
Implement `Actions\HasSharedOperationFeedback` and use
`Actions\InteractsWithSharedOperationFeedback` on the Livewire host. Provide
the source via `sharedOperationSource()` and implement
`onSharedOperationEvent()` and `onSharedOperationState()`; return `true` from
the latter to stop following. Call `subscribeToSharedOperation($id)` before
starting or joining work. The package polls while subscriptions exist.

Subscriptions and cursors are per Livewire component and do not survive a
reload. Authorization is checked on subscription and each poll; the source
must reject missing or inaccessible operations, including unauthenticated
users where appropriate. Callbacks are UI feedback, not durable side effects:
requests or callbacks may be repeated or interrupted. Sources own their
ordering, retention, recovery and state semantics. This API is separate from
`JobAction` and does not change its execution, claim, retry or callback rules.

## How job feedback works

- A **run** is one tracked execution of a queued job, including its retries.
- A **deduplication key** tells feedback actions which active run they can join instead of enqueueing another job. It does not stop ordinary Laravel dispatches.
- A **subscription** lets one Livewire component follow a run for a specific action and keep track of the callbacks it has delivered. Closing the tab doesn't stop the job or its server-side tracking.

### Polling and reactive state

The package automatically adds one poller to each Livewire component that needs job updates; no extra Blade view is required. Page, row, and bulk actions on the same component share it. While a job is queued, processing, or waiting for a retry, its trigger is disabled and displays Filament's loading spinner between polls. The poll that delivers completion or failure re-renders the host and restores the trigger, subject to any normal Filament `disabled()` conditions. Include `filament-job-feedback::status` if you want to show which lifecycle state the job is in.

- Set `polling_interval` in the published `filament-job-feedback.php` config (default `3s`), override it with `->pollingInterval('750ms')`, or set the protected `$pollingInterval` default in a custom action subclass. The shared poller uses the shortest interval requested by actions that currently need updates or idle discovery.
- By default, an idle action doesn't poll. An authorized action that can determine its job's deduplication identity before submission follows a matching active run when it renders.
- To let already-open tabs discover shared runs without any interaction, set `discover_shared_jobs` to `true` globally, or use `->discoverSharedJobs()` on a keyed `JobAction`. This keeps polling while idle for that action. Use `->discoverSharedJobs(false)` to opt out when enabled globally. A bulk action determines its keys from the submitted selection and follows those runs until the batch finishes.
- Lifecycle callbacks run on the host's Livewire request. Use them to set Filament form state, partially refresh saved fields with `refreshFormData()`, or dispatch a Livewire event for a separate component. Polling re-renders the host; it does not overwrite editable form state or refresh separate Livewire components automatically.
- Polling stops when the component no longer has updates to deliver or actions opted into idle discovery. Separate Livewire components poll independently. Server-side tracking and deduplication continue even when a component is unmounted.

### Dispatch conditions and callbacks

- Use `queueIf()` for additional dispatch conditions. Configure the job through `job()`, not Filament's `action()` or `url()`.
- `onJobQueued()`, `onJobProcessing()`, `onJobRetrying()`, `onJobCompleted()`, and `onJobFailed()` run on the Livewire request. Callbacks can receive the `Execution` snapshot, the event's `$status`, and `$attempt`. Use them for UI feedback, not side effects requiring exactly-once delivery.
- If a Laravel worker reports a timeout without failing the job, the run stays active and the status view shows **Job timed out; waiting for retry.** `onJobRetrying()` receives `ExecutionStatus::TimedOut` for that attempt. Laravel controls when the job can be picked up again; the package does not dispatch a replacement. If Laravel marks the timeout as a terminal failure, only the normal failure update is delivered.

### Deduplicating and following jobs

Tracking, sharing feedback, and blocking an enqueue are separate behaviors. You can use them together:

| Mechanism | What it does |
| --- | --- |
| Lifecycle tracking | Records an independent run and its queue events. Tracking alone doesn't prevent duplicate dispatches. |
| `ShouldBeDeduplicated` | Lets a feedback action join an active run with a matching key, including an observed ordinary dispatch. It doesn't change ordinary dispatch behavior. |
| Laravel `ShouldBeUnique` / `ShouldBeUniqueUntilProcessing` | Blocks enqueue attempts that honor Laravel's unique lock, including both feedback action types. The lock alone doesn't give an action a run to follow. |

By default, the package observes ordinary Laravel dispatches using the UUID Laravel assigns to each queue payload. Set `track_external_jobs` to `false` in the package config to track only jobs dispatched by feedback actions. The package doesn't scan older queue entries or cover producers that bypass Laravel's payload hooks.

`ShouldBeDeduplicated` extends Laravel's `ShouldQueue`. By default, it matches by exact job class and ignores constructor arguments. Define a public `deduplicationId(): string` when records, users, or tenants should have independent runs. The key is not a substitute for Filament authorization:

```php
use Illuminate\Foundation\Queue\Queueable;
use SkyRaptor\FilamentJobFeedback\Contracts\ShouldBeDeduplicated;

final class RebuildUserIndex implements ShouldBeDeduplicated
{
    use Queueable;

    public function __construct(private readonly int $userId) {}

    public function deduplicationId(): string
    {
        return (string) $this->userId;
    }

    public function handle(): void
    {
        // Rebuild this user's index.
    }
}
```

A job object supplied to `job()` lets the action discover a matching run before submission. Factories run only on submission by default. If a factory only needs server-side context (such as the bound record), pass `discover: true` to `job()` to also allow render-time discovery. Keep discoverable factories and `deduplicationId()` free of side effects; they can be evaluated repeatedly. Factories that need submitted modal data should use the default.

An ordinary dispatch racing a feedback action can still enqueue a duplicate. If multiple ordinary dispatches already queued the same identity, their histories remain separate and new viewers follow the first observed active run. Enqueue deduplication does not guarantee exactly-once execution.

When a job also uses Laravel uniqueness, the action first looks for an active matching run. It can still join after Laravel releases a `ShouldBeUniqueUntilProcessing` lock. If there's no match and the Laravel lock blocks enqueue, the action warns without subscribing; other eligible bulk records can still queue. Laravel controls that lock's identity, lifetime, and cache store, so make sure dispatchers and workers share that store, including any `uniqueVia()` override.

### Recovering a stale deduplication claim

If a deduplicated execution was last seen processing, the status view marks it stale after the attempt's start time plus its declared job timeout and `stale_grace_seconds` (30 seconds by default). If the job has no declared timeout, set `stale_fallback_timeout_seconds` to reflect your workers; Laravel's processing event does not expose the worker's `--timeout`. A missing or nonpositive timeout without an applicable fallback does not yield an estimate. Queued jobs and jobs waiting for a known retry are not timed by this rule. A newly observed processing attempt starts its own clock.

On actions where it is safe for the original and replacement jobs to overlap, opt in with `->allowStaleJobReplacement()` (or pass a condition). The original trigger shows a **Stale** badge with a warning icon when recovery is available and keeps its label and spinner. No separate status view is needed. Clicking it reopens the original Filament action modal for confirmation, with a **Replacing a stale run** callout above the modal title. The callout’s icon and heading use the theme’s warning color. **Keep waiting** stays available during background polls and leaves the claim in place. The replacement must target the same logical job, pass the usual authorization and `queueIf()` checks, and honor Laravel's native uniqueness lock. A bulk recovery considers all eligible stale records already tracked by that bulk action, not the current table selection. The original trigger follows the replacement, as do other authorized subscribers on their next poll.

If the page was already tracking a run when the attempt became stale, polling shows a Filament warning toast once for that attempt. Bulk actions show one warning while the action stays stale, even if more records become stale on later polls. Reloading into an already-stale run does not replay the toast. Bulk submissions and replacements show one **X of Y job requests accepted** summary; acceptance is not job completion.

When a row action replaces a run tracked by a bulk action on the same table, both triggers follow the replacement, but only the submitting action receives its lifecycle callbacks on that page. The same applies when a bulk action replaces a run also tracked by a row action.

Starting a new run does not cancel the original or prevent Laravel from retrying it. The application is responsible for cleanup and idempotency. The former execution keeps its own history, but its outcome remains unknown unless a real lifecycle event reports it. Detaching a claim does not trigger completion or failure callbacks.

For automatic reconciliation, bind an implementation of `Contracts\ExecutionReconciler` in your application's service provider. Its `state(Execution $execution): ExecutionClaimState` method receives the exact tracking ID and returns:

- `Unknown` when the integration cannot verify the state (including a missing record). Manual recovery remains available on opted-in actions.
- `Active` when the execution is still queued, processing, or eligible for delivery. The claim stays in place, and a manual replacement is blocked.
- `Terminal` only when the execution is no longer running and cannot be delivered again. The package releases that execution's claim without dispatching a replacement or assuming success or failure.

Keep this lookup inexpensive and read-only: it can run during action discovery, submission, or polling. No custom queue driver is required for manual recovery.

Completion or failure releases the feedback claim and keeps the run available to existing viewers for 24 hours. If Laravel rejects the run's payload during JSON encoding, before sending it, the package marks that run discarded and automatically releases both the feedback claim and any Laravel unique lock it acquired. The original exception still reaches the caller. An ambiguous push or a job that disappears without a terminal event can still leave the claim active; the package does not expire active claims based on age.

### Table bulk actions

On a Filament table's Livewire host, implement `HasJobFeedback` and use `InteractsWithJobFeedback`. No separate action-name list is required. Register `JobBulkAction` in the table's `toolbarActions()`:

```php
use SkyRaptor\FilamentJobFeedback\Actions\JobBulkAction;

JobBulkAction::make('rebuildUserIndexes')
    ->requiresConfirmation()
    ->authorize('rebuildAnyUserIndex')
    ->authorizeIndividualRecords('rebuildIndex')
    ->job(fn (User $record) => new RebuildUserIndex($record->getKey()));
```

The action processes each eligible selected record independently: it queues a job, joins an existing feedback run, or skips the record if Laravel's unique lock blocks dispatch. The job factory, `queueIf()`, and lifecycle callbacks can receive that record. Apply the same tenant scoping to the job's identity as you do to the table query. Polling reloads the record through the table and rechecks authorization. A tab starts watching a bulk execution by submitting a selection that includes that record. A row action on the same table can reflect that run's status without firing a second set of callbacks when it only discovered the run started or joined by the bulk action.

Rows can remain selectable even when an individual bulk action cannot process them. When any selected records are skipped, `JobBulkAction` reports how many requests were accepted and why the others were skipped. Accepted requests include both new dispatches and joins to active runs; the summary does not report job completion.

Submitting a job bulk action and polling its runs preserve the table selection by default. Use Filament's `deselectRecordsAfterCompletion()` to clear the selection when the action finishes submitting, rather than when the queued jobs finish.

### Database transactions

Both job actions support Filament's per-action `databaseTransaction()` and panel-wide `databaseTransactions()`. Use `$job->afterCommit()`, Laravel's `ShouldQueueAfterCommit` contract, or the queue connection's `after_commit` option when a job needs committed data. The action evaluates its job factory during submission, but claims or joins the execution and subscribes to it only after the outer transaction commits. If that transaction rolls back, the deferred submission creates no feedback run and takes no uniqueness lock.

An explicit `beforeCommit()` still allows an immediate push. If the built-in database queue inserts the job on a database connection with an open transaction, rolling that transaction back also clears the action's tracking claim. An immediate push to a separate queue backend cannot be undone by a database rollback.

Keep the commit inside the Livewire action request, as Filament does, so deferred subscriptions and notifications are included in the response.

## Optional publishing

Publish all package resources with `php artisan vendor:publish --tag="filament-job-feedback"`, or publish only what you need:

| Resource | Publish tag |
| --- | --- |
| Config | `filament-job-feedback-config` |
| Views | `filament-job-feedback-views` |
| Translations | `filament-job-feedback-lang` |
| Public assets | `filament-job-feedback-assets` |

## Maintainer walkthrough

1. **Submit.** [`JobAction`](src/Actions/JobAction.php) or [`JobBulkAction`](src/Actions/JobBulkAction.php) supplies the job to [`FilamentJobFeedback`](src/FilamentJobFeedback.php). For an after-commit submission, the dispatch service waits before claiming or joining a run.
2. **Claim or join.** [`CacheExecutionStore`](src/CacheExecutionStore.php) checks for a matching active run under an atomic lock. If there is no match, it only publishes a new claim after Laravel's unique lock, if required, permits dispatch.
3. **Track queue events.** The [service provider](src/FilamentJobFeedbackServiceProvider.php) tags the payload. [`LaravelQueueMonitor`](src/LaravelQueueMonitor.php) turns queue events into lifecycle updates, and [`Execution`](src/Execution.php) decides which transitions belong in the run's history.
4. **Deliver feedback.** [`InteractsWithJobFeedback`](src/Actions/InteractsWithJobFeedback.php) resolves the current action and rechecks access and identity. Each subscription advances through the history independently; the worker never invokes UI callbacks.

## Workbench preview

The Workbench opens the default Filament dashboard at `/workbench`. The **Job Feedback Examples** sidebar group contains:

- **Documents** (`/workbench/documents`): Create, view, edit, and delete records in a native resource. Count words from a table row, a bulk selection, or the edit-page header. The worker saves the count on the document, and all three actions share one active run per document. To preview stale recovery without a worker, use **Simulate lost update** on a published row, or select two published documents and choose **Simulate lost updates for selected documents**. Leave **Drop the completion updates** on to see the spinner, then wait about 31 seconds with the default settings for the **Stale** badge. Open the same action, turn off the toggle, and choose **Start a new run**. For the bulk action, you can deselect one row before reopening it: both tracked stale runs are replaced even though only one row is selected. Each successful replacement shows a completion toast without changing the document.
- **Global maintenance** (`/workbench/global-maintenance`): Try a shared search-index rebuild across browser tabs. To preview stale-claim recovery without a worker, choose **Simulate a lost update** with **Drop the completion update** enabled. After about 31 seconds with the default settings, click the same button when it shows **Stale**. Turn off the toggle and choose **Start a new run** to let the replacement finish normally.
- **Report jobs** (`/workbench/report-jobs`): Submit a form to start independent jobs. Its toggles demonstrate `queueIf()` and retry-to-failure.

1. Copy `workbench/.env.example` to `workbench/.env` if you don't already have one. Generate a local key with `php vendor/bin/testbench key:generate --show` and set `APP_KEY` in `workbench/.env`.
2. In VS Code, run `composer build` once to prepare the preview database and assets. Allow automatic tasks for the trusted workspace, then reopen the folder or run **Tasks: Run Task → Workbench: Start**. VS Code starts the server at `http://127.0.0.1:8000/workbench` and runs `queue:listen` in split panes of the same terminal group. The Dev Containers forward port 8000. The tasks do not rebuild or reseed on every open.
3. Outside VS Code, run `composer serve` and open the URL it prints. In a second terminal, run `TESTBENCH_WORKING_PATH="$PWD" php vendor/bin/testbench queue:listen --sleep=1` (Bash). The listener boots a fresh worker for each job, so saved PHP changes are picked up by the next worker.

Open two tabs to try the shared action. The queued job pauses briefly so you can see the lifecycle updates.

The VS Code serve task uses `PHP_CLI_SERVER_WORKERS=4` to handle concurrent HTTP requests, with a single worker on native Windows. Laravel requires `--no-reload` for this mode, so restart **Workbench: Serve** after changing `.env`. The queue listener is unaffected.

The preview uses SQLite for its queue and the file cache so the server and worker can share state without additional services. These are Workbench defaults, not package requirements.

Run `php vendor/bin/pest tests/Feature/WorkbenchTest.php` for the preview smoke tests. Package behavior is covered separately in [`tests/Feature`](tests/Feature), using dedicated action, table, and queue fixtures instead of the demo pages. The PHP feature tests don't need a running server or a separate worker.

### Browser tests

With Composer dependencies installed and Node.js 24 available, run:

```bash
npm ci
npm run test:browser
```

`npm ci` downloads Chromium automatically through `@playwright/browser-chromium`. On Linux, run `npx playwright install-deps chromium` if browser system libraries are missing. The suite starts a private Workbench instance and advances the queue worker one job at a time. It does not use or change your preview database, cache, or `.env`. CI runs it separately from `composer test` on Filament 4 and 5, and uploads Playwright traces on failure.

Both Playwright dependencies allow patch updates. Update them together with `npm update @playwright/test @playwright/browser-chromium`; `npm ci` continues to use the exact versions in `package-lock.json`.

The shared-job race test uses two Workbench server processes and holds both authenticated submissions just before they claim the job. It verifies that only one job is enqueued and that both tabs receive completion and stop polling.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Filament Job Feedback! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Patrick Henninger](https://github.com/skyraptor)
- [All Contributors](../../contributors)

## License

Filament Job Feedback is open-sourced software licensed under the
[GNU Affero General Public License, version 3 only](COPYING) with the
[Library Integration Exception](COPYING.EXCEPTION).
