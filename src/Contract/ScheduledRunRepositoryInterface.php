<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Contract;

use Semitexa\Scheduler\Domain\Model\ScheduledRun;

interface ScheduledRunRepositoryInterface
{
    public function findById(string $id): ?ScheduledRun;

    public function findByOccurrenceKey(string $occurrenceKey): ?ScheduledRun;

    public function save(object $entity): void;

    /**
     * Atomically claim one run for the given worker and pool.
     * Returns the claimed run id, or null if none available.
     */
    public function claimNextDue(string $pool, string $workerId, int $leaseTtlSeconds): ?string;

    /**
     * Renew the lease on a run owned by this worker.
     * Returns false if the lease was lost.
     */
    public function renewLease(string $runId, string $workerId, int $leaseTtlSeconds): bool;

    /**
     * Reclaim runs whose lease has expired (crash recovery).
     * Returns the number of rows reclaimed back to pending.
     */
    public function reclaimExpiredLeases(\DateTimeImmutable $now): int;
}
