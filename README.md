# semitexa/scheduler

Recurring and delayed background jobs with lease-based workers, retry logic, and overlap protection.

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

## Notes

The scheduler is designed for Swoole long-running processes. Workers maintain leases via heartbeat and release them on graceful shutdown.
