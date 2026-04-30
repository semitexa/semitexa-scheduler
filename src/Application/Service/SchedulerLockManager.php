<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Domain\Contract\SchedulerLockRepositoryInterface;

final class SchedulerLockManager
{
    public function __construct(
        private readonly SchedulerLockRepositoryInterface $lockRepository,
        private readonly int $lockTtlSeconds,
    ) {}

    public function acquire(string $lockKey, string $runId, string $workerId): bool
    {
        return $this->lockRepository->acquire($lockKey, $runId, $workerId, $this->lockTtlSeconds);
    }

    public function release(string $lockKey, string $workerId): void
    {
        $this->lockRepository->release($lockKey, $workerId);
    }

    public function extend(string $lockKey, string $workerId): bool
    {
        return $this->lockRepository->extend($lockKey, $workerId, $this->lockTtlSeconds);
    }

    public function cleanup(\DateTimeImmutable $now): int
    {
        return $this->lockRepository->deleteExpired($now);
    }
}
