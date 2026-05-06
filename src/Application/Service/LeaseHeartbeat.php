<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Application\Service\RunLeaseManager;
use Semitexa\Scheduler\Application\Service\SchedulerLockManager;

/**
 * Provides a tick() method for renewing lease and lock during long-running job execution.
 * Call tick() at natural checkpoints within a job to prevent lease expiry.
 */
final class LeaseHeartbeat
{
    public function __construct(
        private readonly RunLeaseManager $leaseManager,
        private readonly SchedulerLockManager $lockManager,
        private readonly string $runId,
        private readonly string $workerId,
        private readonly ?string $lockKey,
    ) {}

    public function tick(): void
    {
        $this->leaseManager->renewLease($this->runId, $this->workerId);

        if ($this->lockKey !== null) {
            $this->lockManager->extend($this->lockKey, $this->workerId);
        }
    }
}
