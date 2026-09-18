<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

final class ScheduleDefinition
{
    private string $id = '';
    private string $scheduleKey = '';
    private string $jobClass = '';
    private string $cronExpression = '';
    private string $timezone = 'UTC';
    private string $pool = 'default';
    private string $overlapPolicy = 'skip';
    private string $misfirePolicy = 'run_once';
    private string $tenantMode = 'global';
    private ?int $maxCatchUpRuns = null;
    private int $maxAttempts = 1;
    private int $retryBackoffSeconds = 0;
    private bool $enabled = true;
    private ?\DateTimeImmutable $planningCursorAt = null;
    private ?\DateTimeImmutable $lastPlannedAt = null;
    private ?string $payloadTemplateJson = null;
    private ?\DateTimeImmutable $createdAt = null;
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        string $id = '',
        string $scheduleKey = '',
        string $jobClass = '',
        string $cronExpression = '',
        string $timezone = 'UTC',
        string $pool = 'default',
        string $overlapPolicy = 'skip',
        string $misfirePolicy = 'run_once',
        string $tenantMode = 'global',
        ?int $maxCatchUpRuns = null,
        int $maxAttempts = 1,
        int $retryBackoffSeconds = 0,
        bool $enabled = true,
        ?\DateTimeImmutable $planningCursorAt = null,
        ?\DateTimeImmutable $lastPlannedAt = null,
        ?string $payloadTemplateJson = null,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->scheduleKey = $scheduleKey;
        $this->jobClass = $jobClass;
        $this->cronExpression = $cronExpression;
        $this->timezone = $timezone;
        $this->pool = $pool;
        $this->overlapPolicy = $overlapPolicy;
        $this->misfirePolicy = $misfirePolicy;
        $this->tenantMode = $tenantMode;
        $this->maxCatchUpRuns = $maxCatchUpRuns;
        $this->maxAttempts = $maxAttempts;
        $this->retryBackoffSeconds = $retryBackoffSeconds;
        $this->enabled = $enabled;
        $this->planningCursorAt = $planningCursorAt;
        $this->lastPlannedAt = $lastPlannedAt;
        $this->payloadTemplateJson = $payloadTemplateJson;
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

    public function getScheduleKey(): string
    {
        return $this->scheduleKey;
    }

    public function setScheduleKey(string $scheduleKey): void
    {
        $this->scheduleKey = $scheduleKey;
    }

    public function getJobClass(): string
    {
        return $this->jobClass;
    }

    public function setJobClass(string $jobClass): void
    {
        $this->jobClass = $jobClass;
    }

    public function getCronExpression(): string
    {
        return $this->cronExpression;
    }

    public function setCronExpression(string $cronExpression): void
    {
        $this->cronExpression = $cronExpression;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
    }

    public function getPool(): string
    {
        return $this->pool;
    }

    public function setPool(string $pool): void
    {
        $this->pool = $pool;
    }

    public function getOverlapPolicy(): string
    {
        return $this->overlapPolicy;
    }

    public function setOverlapPolicy(string $overlapPolicy): void
    {
        $this->overlapPolicy = $overlapPolicy;
    }

    public function getMisfirePolicy(): string
    {
        return $this->misfirePolicy;
    }

    public function setMisfirePolicy(string $misfirePolicy): void
    {
        $this->misfirePolicy = $misfirePolicy;
    }

    public function getTenantMode(): string
    {
        return $this->tenantMode;
    }

    public function setTenantMode(string $tenantMode): void
    {
        $this->tenantMode = $tenantMode;
    }

    public function getMaxCatchUpRuns(): ?int
    {
        return $this->maxCatchUpRuns;
    }

    public function setMaxCatchUpRuns(?int $maxCatchUpRuns): void
    {
        $this->maxCatchUpRuns = $maxCatchUpRuns;
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getPlanningCursorAt(): ?\DateTimeImmutable
    {
        return $this->planningCursorAt;
    }

    public function setPlanningCursorAt(?\DateTimeImmutable $planningCursorAt): void
    {
        $this->planningCursorAt = $planningCursorAt;
    }

    public function getLastPlannedAt(): ?\DateTimeImmutable
    {
        return $this->lastPlannedAt;
    }

    public function setLastPlannedAt(?\DateTimeImmutable $lastPlannedAt): void
    {
        $this->lastPlannedAt = $lastPlannedAt;
    }

    public function getPayloadTemplateJson(): ?string
    {
        return $this->payloadTemplateJson;
    }

    public function setPayloadTemplateJson(?string $payloadTemplateJson): void
    {
        $this->payloadTemplateJson = $payloadTemplateJson;
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
