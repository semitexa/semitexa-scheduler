<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Contract;

use Semitexa\Scheduler\Domain\Model\ScheduledRun;

interface ScheduledRunRepositoryInterface
{
    public function findById(string $id): ?ScheduledRun;

    public function findByOccurrenceKey(string $occurrenceKey): ?ScheduledRun;

    public function save(ScheduledRun $entity): void;

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
     * Persist a run's outcome (status, available_at, last_error, finished_at
     * and the cleared lease) only while the row's lease_owner is still
     * $expectedOwner — the owner the run carried when this worker took it
     * (null for an unleased inline run). Returns false and writes nothing
     * when another worker has reclaimed or taken over the run since.
     */
    public function finalizeIfOwned(ScheduledRun $run, ?string $expectedOwner): bool;

    /**
     * Reclaim runs whose lease has expired (crash recovery).
     * Returns the number of rows reclaimed back to pending.
     */
    public function reclaimExpiredLeases(\DateTimeImmutable $now): int;
}
