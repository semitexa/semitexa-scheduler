<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

final class ScheduledRun
{
    private string $id = '';
    private string $sourceType = 'delayed';
    private ?string $scheduleDefinitionId = null;
    private ?string $scheduleKey = null;
    private ?string $occurrenceKey = null;
    private string $jobClass = '';
    private ?string $tenantId = null;
    private string $pool = 'default';
    private ?string $lockKey = null;
    private string $status = 'pending';
    private ?\DateTimeImmutable $scheduledFor = null;
    private ?\DateTimeImmutable $availableAt = null;
    private ?\DateTimeImmutable $misfiredAt = null;
    private int $attemptCount = 0;
    private int $maxAttempts = 1;
    private int $retryBackoffSeconds = 0;
    private ?string $leaseOwner = null;
    private ?\DateTimeImmutable $leaseExpiresAt = null;
    private ?\DateTimeImmutable $lockedAt = null;
    private ?\DateTimeImmutable $startedAt = null;
    private ?\DateTimeImmutable $finishedAt = null;
    private ?\DateTimeImmutable $lastHeartbeatAt = null;
    private ?string $lastError = null;
    private ?string $payloadJson = null;
    private ?\DateTimeImmutable $createdAt = null;
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): void
    {
        $this->sourceType = $sourceType;
    }

    public function getScheduleDefinitionId(): ?string
    {
        return $this->scheduleDefinitionId;
    }

    public function setScheduleDefinitionId(?string $scheduleDefinitionId): void
    {
        $this->scheduleDefinitionId = $scheduleDefinitionId;
    }

    public function getScheduleKey(): ?string
    {
        return $this->scheduleKey;
    }

    public function setScheduleKey(?string $scheduleKey): void
    {
        $this->scheduleKey = $scheduleKey;
    }

    public function getOccurrenceKey(): ?string
    {
        return $this->occurrenceKey;
    }

    public function setOccurrenceKey(?string $occurrenceKey): void
    {
        $this->occurrenceKey = $occurrenceKey;
    }

    public function getJobClass(): string
    {
        return $this->jobClass;
    }

    public function setJobClass(string $jobClass): void
    {
        $this->jobClass = $jobClass;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function getPool(): string
    {
        return $this->pool;
    }

    public function setPool(string $pool): void
    {
        $this->pool = $pool;
    }

    public function getLockKey(): ?string
    {
        return $this->lockKey;
    }

    public function setLockKey(?string $lockKey): void
    {
        $this->lockKey = $lockKey;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getScheduledFor(): ?\DateTimeImmutable
    {
        return $this->scheduledFor;
    }

    public function setScheduledFor(?\DateTimeImmutable $scheduledFor): void
    {
        $this->scheduledFor = $scheduledFor;
    }

    public function getAvailableAt(): ?\DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function setAvailableAt(?\DateTimeImmutable $availableAt): void
    {
        $this->availableAt = $availableAt;
    }

    public function getMisfiredAt(): ?\DateTimeImmutable
    {
        return $this->misfiredAt;
    }

    public function setMisfiredAt(?\DateTimeImmutable $misfiredAt): void
    {
        $this->misfiredAt = $misfiredAt;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function setAttemptCount(int $attemptCount): void
    {
        $this->attemptCount = $attemptCount;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): void
    {
        $this->maxAttempts = $maxAttempts;
    }

    public function getRetryBackoffSeconds(): int
    {
        return $this->retryBackoffSeconds;
    }

    public function setRetryBackoffSeconds(int $retryBackoffSeconds): void
    {
        $this->retryBackoffSeconds = $retryBackoffSeconds;
    }

    public function getLeaseOwner(): ?string
    {
        return $this->leaseOwner;
    }

    public function setLeaseOwner(?string $leaseOwner): void
    {
        $this->leaseOwner = $leaseOwner;
    }

    public function getLeaseExpiresAt(): ?\DateTimeImmutable
    {
        return $this->leaseExpiresAt;
    }

    public function setLeaseExpiresAt(?\DateTimeImmutable $leaseExpiresAt): void
    {
        $this->leaseExpiresAt = $leaseExpiresAt;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?\DateTimeImmutable $lockedAt): void
    {
        $this->lockedAt = $lockedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    public function getLastHeartbeatAt(): ?\DateTimeImmutable
    {
        return $this->lastHeartbeatAt;
    }

    public function setLastHeartbeatAt(?\DateTimeImmutable $lastHeartbeatAt): void
    {
        $this->lastHeartbeatAt = $lastHeartbeatAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): void
    {
        $this->lastError = $lastError;
    }

    public function getPayloadJson(): ?string
    {
        return $this->payloadJson;
    }

    public function setPayloadJson(?string $payloadJson): void
    {
        $this->payloadJson = $payloadJson;
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
