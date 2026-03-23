<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

final class ScheduleDefinition
{
    public string $id = '';
    public string $scheduleKey = '';
    public string $jobClass = '';
    public string $cronExpression = '';
    public string $timezone = 'UTC';
    public string $pool = 'default';
    public string $overlapPolicy = 'skip';
    public string $misfirePolicy = 'run_once';
    public string $tenantMode = 'global';
    public ?int $maxCatchUpRuns = null;
    public int $maxAttempts = 1;
    public int $retryBackoffSeconds = 0;
    public bool $enabled = true;
    public ?\DateTimeImmutable $planningCursorAt = null;
    public ?\DateTimeImmutable $lastPlannedAt = null;
    public ?string $payloadTemplateJson = null;
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;

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
}
