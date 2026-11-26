# Filament Job Feedback

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Concept

This package dispatches queued Laravel jobs from Filament actions and lets the UI react to their lifecycle.

### Job Monitoring

The package monitors enqueue, processing, retries, completion, and failure.

Monitoring should be implemented in a dependency-free manner wherever possible. Ideally, Jobs should not require modifications or additional dependencies to be monitored.

However, monitoring must follow a well-defined contract that allows custom implementations and integrations to provide alternative monitoring mechanisms.

### Guarantees

The package must provide the following guarantees to ensure reliable job tracking across multiple browser tabs, users and sessions.

#### Isolation

*Independent* Jobs must be tracked independently, regardless of where or how they are triggered.

- Jobs triggered from different browser tabs or sessions must never interfere with each other.
- Each independent Job execution must have its own tracking context.
- Lifecycle updates must always be associated with the correct Job execution.

#### Logical Deduplication

Jobs opt into feedback deduplication with `ShouldBeDeduplicated`. Matching uses the job class by default; jobs can define `deduplicationId()` to distinguish runs of the same class.

When a logically identical Job is already queued or processing:

- No additional Job must be dispatched.
- The new request must attach to the existing Job execution and inherit its tracking context.
- All attached requests must receive the same lifecycle updates.
- The Job must only be dispatched once.

Logical identity may be based on input data, expected results, target resources or other application-specific criteria.

## Filament PHP

### General Methods

There should be various fluent methods for configuring the Filament PHP Components provided by this package.

**General:**
- `job()`: Provides the Job to be queued.
- `queueIf()` Determines whether the Job should be queued.

**Lifecycle:**
- `onJobQueued()` Defines what to do once the Job has been dispatched to the queue.
- `onJobProcessing()` Defines what to do once the Job has started processing.
- `onJobRetrying()` Defines what to do in case the Job is being retried.
- `onJobCompleted()` Defines what to do once the Job has been completed.
- `onJobFailed()` Defines what to do in case the Job has failed.

### Actions

By providing Filament PHP Actions, it should allow for the simple integration wherever Actions can be used.

#### JobAction

The `JobAction` is a Filament PHP Action that allows users to run a queued Job and receive feedback about its execution.

The `url()` method is not supported, as a `JobAction` must dispatch the Job when executed.

#### JobBulkAction

The `JobBulkAction` is a Filament PHP Bulk Action that allows users to dispatch multiple queued Jobs and receive feedback on their execution.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `skyraptor/filament-job-feedback`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.


## Architecture and Lifecycle

- Design components around cohesive responsibilities and explicit lifecycle boundaries, not merely conceptual grouping. Identify their states, transitions, invariants, and operation prerequisites before introducing or changing lifecycle-related abstractions.
- Avoid partially initialized objects and implicit ordering requirements where practical. Make lifecycle prerequisites explicit through APIs, types, composition, or dedicated coordinators rather than scattering state checks and assumptions across methods.
- Keep state transitions predictable and independently testable. Account for invalid transitions, failures, retries, recovery, and cleanup where applicable.
- Prefer eliminating unnecessary states and simplifying transitions over introducing abstractions or design patterns solely to model them. The resulting architecture should be easy to understand, test, maintain, and represent in UML.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.
