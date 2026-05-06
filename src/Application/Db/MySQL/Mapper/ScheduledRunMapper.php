<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunResource;

#[AsMapper(
    resourceModel: SchedulerRunResource::class,
    domainModel: ScheduledRun::class
)]
final class ScheduledRunMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof SchedulerRunResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new ScheduledRun(
            id: $resourceModel->id,
            sourceType: $resourceModel->source_type,
            scheduleDefinitionId: $resourceModel->schedule_definition_id,
            scheduleKey: $resourceModel->schedule_key,
            occurrenceKey: $resourceModel->occurrence_key,
            jobClass: $resourceModel->job_class,
            tenantId: $resourceModel->tenant_id,
            pool: $resourceModel->pool,
            lockKey: $resourceModel->lock_key,
            status: $resourceModel->status,
            scheduledFor: $resourceModel->scheduled_for,
            availableAt: $resourceModel->available_at,
            misfiredAt: $resourceModel->misfired_at,
            attemptCount: $resourceModel->attempt_count,
            maxAttempts: $resourceModel->max_attempts,
            retryBackoffSeconds: $resourceModel->retry_backoff_seconds,
            leaseOwner: $resourceModel->lease_owner,
            leaseExpiresAt: $resourceModel->lease_expires_at,
            lockedAt: $resourceModel->locked_at,
            startedAt: $resourceModel->started_at,
            finishedAt: $resourceModel->finished_at,
            lastHeartbeatAt: $resourceModel->last_heartbeat_at,
            lastError: $resourceModel->last_error,
            payloadJson: $resourceModel->payload_json,
            createdAt: $resourceModel->created_at,
            updatedAt: $resourceModel->updated_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof ScheduledRun || throw new \InvalidArgumentException('Unexpected domain model.');

        $resource = new SchedulerRunResource();
        $resource->id = $domainModel->id;
        $resource->source_type = $domainModel->sourceType;
        $resource->schedule_definition_id = $domainModel->scheduleDefinitionId;
        $resource->schedule_key = $domainModel->scheduleKey;
        $resource->occurrence_key = $domainModel->occurrenceKey;
        $resource->job_class = $domainModel->jobClass;
        $resource->tenant_id = $domainModel->tenantId;
        $resource->pool = $domainModel->pool;
        $resource->lock_key = $domainModel->lockKey;
        $resource->status = $domainModel->status;
        $resource->scheduled_for = $domainModel->scheduledFor;
        $resource->available_at = $domainModel->availableAt;
        $resource->misfired_at = $domainModel->misfiredAt;
        $resource->attempt_count = $domainModel->attemptCount;
        $resource->max_attempts = $domainModel->maxAttempts;
        $resource->retry_backoff_seconds = $domainModel->retryBackoffSeconds;
        $resource->lease_owner = $domainModel->leaseOwner;
        $resource->lease_expires_at = $domainModel->leaseExpiresAt;
        $resource->locked_at = $domainModel->lockedAt;
        $resource->started_at = $domainModel->startedAt;
        $resource->finished_at = $domainModel->finishedAt;
        $resource->last_heartbeat_at = $domainModel->lastHeartbeatAt;
        $resource->last_error = $domainModel->lastError;
        $resource->payload_json = $domainModel->payloadJson;
        $resource->created_at = $domainModel->createdAt;
        $resource->updated_at = $domainModel->updatedAt;
        return $resource;
    }
}
