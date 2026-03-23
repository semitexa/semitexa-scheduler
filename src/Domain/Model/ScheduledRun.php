<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

final class ScheduledRun
{
    public string $id = '';
    public string $sourceType = 'delayed';
    public ?string $scheduleDefinitionId = null;
    public ?string $scheduleKey = null;
    public ?string $occurrenceKey = null;
    public string $jobClass = '';
    public ?string $tenantId = null;
    public string $pool = 'default';
    public ?string $lockKey = null;
    public string $status = 'pending';
    public ?\DateTimeImmutable $scheduledFor = null;
    public ?\DateTimeImmutable $availableAt = null;
    public ?\DateTimeImmutable $misfiredAt = null;
    public int $attemptCount = 0;
    public int $maxAttempts = 1;
    public int $retryBackoffSeconds = 0;
    public ?string $leaseOwner = null;
    public ?\DateTimeImmutable $leaseExpiresAt = null;
    public ?\DateTimeImmutable $lockedAt = null;
    public ?\DateTimeImmutable $startedAt = null;
    public ?\DateTimeImmutable $finishedAt = null;
    public ?\DateTimeImmutable $lastHeartbeatAt = null;
    public ?string $lastError = null;
    public ?string $payloadJson = null;
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        string $id = '',
        string $sourceType = 'delayed',
        ?string $scheduleDefinitionId = null,
        ?string $scheduleKey = null,
        ?string $occurrenceKey = null,
        string $jobClass = '',
        ?string $tenantId = null,
        string $pool = 'default',
        ?string $lockKey = null,
        string $status = 'pending',
        ?\DateTimeImmutable $scheduledFor = null,
        ?\DateTimeImmutable $availableAt = null,
        ?\DateTimeImmutable $misfiredAt = null,
        int $attemptCount = 0,
        int $maxAttempts = 1,
        int $retryBackoffSeconds = 0,
        ?string $leaseOwner = null,
        ?\DateTimeImmutable $leaseExpiresAt = null,
        ?\DateTimeImmutable $lockedAt = null,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $finishedAt = null,
        ?\DateTimeImmutable $lastHeartbeatAt = null,
        ?string $lastError = null,
        ?string $payloadJson = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->sourceType = $sourceType;
        $this->scheduleDefinitionId = $scheduleDefinitionId;
        $this->scheduleKey = $scheduleKey;
        $this->occurrenceKey = $occurrenceKey;
        $this->jobClass = $jobClass;
        $this->tenantId = $tenantId;
        $this->pool = $pool;
        $this->lockKey = $lockKey;
        $this->status = $status;
        $this->scheduledFor = $scheduledFor;
        $this->availableAt = $availableAt;
        $this->misfiredAt = $misfiredAt;
        $this->attemptCount = $attemptCount;
        $this->maxAttempts = $maxAttempts;
        $this->retryBackoffSeconds = $retryBackoffSeconds;
        $this->leaseOwner = $leaseOwner;
        $this->leaseExpiresAt = $leaseExpiresAt;
        $this->lockedAt = $lockedAt;
        $this->startedAt = $startedAt;
        $this->finishedAt = $finishedAt;
        $this->lastHeartbeatAt = $lastHeartbeatAt;
        $this->lastError = $lastError;
        $this->payloadJson = $payloadJson;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }
}
