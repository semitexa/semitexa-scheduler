<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerScheduleDefinitionResource;

#[AsMapper(resourceModel: SchedulerScheduleDefinitionResource::class, domainModel: ScheduleDefinition::class)]
final class ScheduleDefinitionMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof SchedulerScheduleDefinitionResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new ScheduleDefinition(
            id: $resourceModel->id,
            scheduleKey: $resourceModel->schedule_key,
            jobClass: $resourceModel->job_class,
            cronExpression: $resourceModel->cron_expression,
            timezone: $resourceModel->timezone,
            pool: $resourceModel->pool,
            overlapPolicy: $resourceModel->overlap_policy,
            misfirePolicy: $resourceModel->misfire_policy,
            tenantMode: $resourceModel->tenant_mode,
            maxCatchUpRuns: $resourceModel->max_catch_up_runs,
            maxAttempts: $resourceModel->max_attempts,
            retryBackoffSeconds: $resourceModel->retry_backoff_seconds,
            enabled: $resourceModel->enabled,
            planningCursorAt: $resourceModel->planning_cursor_at,
            lastPlannedAt: $resourceModel->last_planned_at,
            payloadTemplateJson: $resourceModel->payload_template_json,
            createdAt: $resourceModel->created_at,
            updatedAt: $resourceModel->updated_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof ScheduleDefinition || throw new \InvalidArgumentException('Unexpected domain model.');

        return new SchedulerScheduleDefinitionResource(
            id: $domainModel->id,
            schedule_key: $domainModel->scheduleKey,
            job_class: $domainModel->jobClass,
            cron_expression: $domainModel->cronExpression,
            timezone: $domainModel->timezone,
            pool: $domainModel->pool,
            overlap_policy: $domainModel->overlapPolicy,
            misfire_policy: $domainModel->misfirePolicy,
            tenant_mode: $domainModel->tenantMode,
            max_catch_up_runs: $domainModel->maxCatchUpRuns,
            max_attempts: $domainModel->maxAttempts,
            retry_backoff_seconds: $domainModel->retryBackoffSeconds,
            enabled: $domainModel->enabled,
            planning_cursor_at: $domainModel->planningCursorAt,
            last_planned_at: $domainModel->lastPlannedAt,
            payload_template_json: $domainModel->payloadTemplateJson,
            created_at: $domainModel->createdAt,
            updated_at: $domainModel->updatedAt,
        );
    }
}
