<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Contract;

use Semitexa\Scheduler\Domain\Model\ScheduledRun;

/**
 * Optional beside {@see ScheduledRunRepositoryInterface}: reads for code outside
 * the scheduler — an alert on failed jobs, an admin list — so it need not read
 * the scheduler_runs table, whose layout is this package's own. The database
 * repository implements it.
 */
interface ScheduledRunListingInterface
{
    /**
     * The runs that failed for good (no retry left) at or after $since, the
     * latest first: up to $limit, at most 500.
     *
     * @return list<ScheduledRun>
     */
    public function failedSince(\DateTimeImmutable $since, int $limit): array;
}
