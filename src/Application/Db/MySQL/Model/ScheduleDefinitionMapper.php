<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;

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

        $resource = new SchedulerScheduleDefinitionResource();
        $resource->id = $domainModel->id;
        $resource->schedule_key = $domainModel->scheduleKey;
        $resource->job_class = $domainModel->jobClass;
        $resource->cron_expression = $domainModel->cronExpression;
        $resource->timezone = $domainModel->timezone;
        $resource->pool = $domainModel->pool;
        $resource->overlap_policy = $domainModel->overlapPolicy;
        $resource->misfire_policy = $domainModel->misfirePolicy;
        $resource->tenant_mode = $domainModel->tenantMode;
        $resource->max_catch_up_runs = $domainModel->maxCatchUpRuns;
        $resource->max_attempts = $domainModel->maxAttempts;
        $resource->retry_backoff_seconds = $domainModel->retryBackoffSeconds;
        $resource->enabled = $domainModel->enabled;
        $resource->planning_cursor_at = $domainModel->planningCursorAt;
        $resource->last_planned_at = $domainModel->lastPlannedAt;
        $resource->payload_template_json = $domainModel->payloadTemplateJson;
        $resource->created_at = $domainModel->createdAt;
        $resource->updated_at = $domainModel->updatedAt;
        return $resource;
    }
}
