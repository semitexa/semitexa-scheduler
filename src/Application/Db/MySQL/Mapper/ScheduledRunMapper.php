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

        return new SchedulerRunResource(
            id: $domainModel->id,
            source_type: $domainModel->sourceType,
            schedule_definition_id: $domainModel->scheduleDefinitionId,
            schedule_key: $domainModel->scheduleKey,
            occurrence_key: $domainModel->occurrenceKey,
            job_class: $domainModel->jobClass,
            tenant_id: $domainModel->tenantId,
            pool: $domainModel->pool,
            lock_key: $domainModel->lockKey,
            status: $domainModel->status,
            scheduled_for: $domainModel->scheduledFor,
            available_at: $domainModel->availableAt,
            misfired_at: $domainModel->misfiredAt,
            attempt_count: $domainModel->attemptCount,
            max_attempts: $domainModel->maxAttempts,
            retry_backoff_seconds: $domainModel->retryBackoffSeconds,
            lease_owner: $domainModel->leaseOwner,
            lease_expires_at: $domainModel->leaseExpiresAt,
            locked_at: $domainModel->lockedAt,
            started_at: $domainModel->startedAt,
            finished_at: $domainModel->finishedAt,
            last_heartbeat_at: $domainModel->lastHeartbeatAt,
            last_error: $domainModel->lastError,
            payload_json: $domainModel->payloadJson,
            created_at: $domainModel->createdAt,
            updated_at: $domainModel->updatedAt,
        );
    }
}
