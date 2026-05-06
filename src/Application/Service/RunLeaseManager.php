<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;

final class RunLeaseManager
{
    public function __construct(
        private readonly ScheduledRunRepositoryInterface $runRepository,
        private readonly int $leaseTtlSeconds,
    ) {}

    public function claimNextDue(string $pool, string $workerId): ?string
    {
        return $this->runRepository->claimNextDue($pool, $workerId, $this->leaseTtlSeconds);
    }

    public function renewLease(string $runId, string $workerId): bool
    {
        return $this->runRepository->renewLease($runId, $workerId, $this->leaseTtlSeconds);
    }

    public function reclaimExpiredLeases(\DateTimeImmutable $now): int
    {
        return $this->runRepository->reclaimExpiredLeases($now);
    }
}
