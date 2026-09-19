<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

final class SchedulerLock
{
    private string $id = '';
    private string $lockKey = '';
    private string $runId = '';
    private string $workerId = '';
    private ?\DateTimeImmutable $acquiredAt = null;
    private ?\DateTimeImmutable $expiresAt = null;
    private ?\DateTimeImmutable $createdAt = null;
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getLockKey(): string
    {
        return $this->lockKey;
    }

    public function setLockKey(string $lockKey): void
    {
        $this->lockKey = $lockKey;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function setRunId(string $runId): void
    {
        $this->runId = $runId;
    }

    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    public function setWorkerId(string $workerId): void
    {
        $this->workerId = $workerId;
    }

    public function getAcquiredAt(): ?\DateTimeImmutable
    {
        return $this->acquiredAt;
    }

    public function setAcquiredAt(?\DateTimeImmutable $acquiredAt): void
    {
        $this->acquiredAt = $acquiredAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
