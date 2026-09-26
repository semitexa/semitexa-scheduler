<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Application\Service\RunLeaseManager;
use Semitexa\Scheduler\Application\Service\SchedulerLockManager;
use Semitexa\Scheduler\Domain\Exception\LeaseLostException;

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

    /**
     * @throws LeaseLostException when this worker no longer owns the lease or
     *         the lock — the job must stop rather than run beside its new owner.
     */
    public function tick(): void
    {
        if (!$this->leaseManager->renewLease($this->runId, $this->workerId)) {
            throw LeaseLostException::forRun($this->runId, $this->workerId);
        }

        if ($this->lockKey !== null && !$this->lockManager->extend($this->lockKey, $this->workerId)) {
            throw LeaseLostException::forLock($this->lockKey, $this->workerId);
        }
    }
}
