<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

final class SchedulerLock
{
    public string $id = '';
    public string $lockKey = '';
    public string $runId = '';
    public string $workerId = '';
    public ?\DateTimeImmutable $acquiredAt = null;
    public ?\DateTimeImmutable $expiresAt = null;
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        string $id = '',
        string $lockKey = '',
        string $runId = '',
        string $workerId = '',
        ?\DateTimeImmutable $acquiredAt = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->lockKey = $lockKey;
        $this->runId = $runId;
        $this->workerId = $workerId;
        $this->acquiredAt = $acquiredAt;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }
}
