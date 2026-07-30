<?php

declare(strict_types=1);

namespace Semitexa\Scheduler;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'scheduler.jobs',
    summary: 'Recurring and delayed background jobs declared with #[AsScheduledJob], leased so two workers never run one twice.',
    useWhen: 'Work has to happen on a clock rather than on a request - nightly reports, retries, cleanup sweeps.',
    avoidWhen: 'The work can finish inside the request that asked for it, or a queue consumer already covers it.',
    replaces: [
        'crontab entries calling CLI scripts, with overlap handled by a lock file',
        'a sleep loop in a worker process that re-checks the time',
    ],
)]
final class Capabilities
{
}
