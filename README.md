# Semitexa Scheduler

Recurring and delayed background jobs with lease-based workers, retry logic, and overlap protection.

## Install

Included in every project created by the installer (https://semitexa.com/install.sh).

## Purpose

Manages scheduled job execution. Jobs are persisted via ORM, leased by workers with heartbeat monitoring, and retried with exponential backoff on failure. Cron expressions define recurring schedules.

## Role in Semitexa

Depends on `semitexa/core`, `semitexa/orm`, and `semitexa/tenancy`. Uses `dragonmantank/cron-expression` for schedule parsing. Jobs execute within tenant context when tenancy is active.

## Key Features

- Cron-based recurring job scheduling
- Lease-based worker execution with heartbeat
- Exponential backoff retry via `RetryScheduler`
- Overlap protection (one execution per schedule)
- `RunStatus` state machine (pending, running, completed, failed)
- Tenant-isolated job execution

## Configuring a schedule per install

`cronExpression` accepts the framework's `env::VAR::default` form:

```php
#[AsScheduledJob(key: 'cms.translate', cronExpression: 'env::CMS_TRANSLATE_CRON::*/5 * * * *')]
```

The attribute is the package's default; `.env` is the install's decision. Editing
`scheduler_schedule_definitions` by hand is not an alternative — `scheduler:plan`
rewrites every field of the row from the attribute on each tick (only `enabled`
is yours to keep). An expression that does not parse is refused with a warning
naming the job, and that job keeps its previous schedule while the rest plan
normally.

## Running it

Neither command starts with the web server. Run them as their own processes:

```bash
bin/semitexa scheduler:plan          # one-shot: materialize due recurring occurrences into run rows; run it periodically
bin/semitexa scheduler:work [pool]   # long-running worker that leases and executes runs (pool defaults to SCHEDULER_DEFAULT_POOL or "default")
```

`scheduler:list` shows enabled schedules and their next due time; `scheduler:run-now` creates an immediate run for a schedule key.

Docs: https://semitexa.com/docs/cli/workers-scheduling

## Notes

The scheduler is designed for Swoole long-running processes. Workers maintain leases via heartbeat and release them on graceful shutdown.
