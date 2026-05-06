<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Contract;

use Semitexa\Scheduler\Domain\Model\SchedulerLock;

interface SchedulerLockRepositoryInterface
{
    /**
     * Try to acquire the lock. Returns true on success, false if already held.
     */
    public function acquire(string $lockKey, string $runId, string $workerId, int $ttlSeconds): bool;

    /**
     * Extend an existing lock held by this worker.
     */
    public function extend(string $lockKey, string $workerId, int $ttlSeconds): bool;

    /**
     * Release the lock held by this worker.
     */
    public function release(string $lockKey, string $workerId): void;

    public function findByKey(string $lockKey): ?SchedulerLock;

    /** Delete expired locks. */
    public function deleteExpired(\DateTimeImmutable $now): int;
}
