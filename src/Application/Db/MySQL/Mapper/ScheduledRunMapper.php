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
            id: $domainModel->getId(),
            source_type: $domainModel->getSourceType(),
            schedule_definition_id: $domainModel->getScheduleDefinitionId(),
            schedule_key: $domainModel->getScheduleKey(),
            occurrence_key: $domainModel->getOccurrenceKey(),
            job_class: $domainModel->getJobClass(),
            tenant_id: $domainModel->getTenantId(),
            pool: $domainModel->getPool(),
            lock_key: $domainModel->getLockKey(),
            status: $domainModel->getStatus(),
            scheduled_for: $domainModel->getScheduledFor(),
            available_at: $domainModel->getAvailableAt(),
            misfired_at: $domainModel->getMisfiredAt(),
            attempt_count: $domainModel->getAttemptCount(),
            max_attempts: $domainModel->getMaxAttempts(),
            retry_backoff_seconds: $domainModel->getRetryBackoffSeconds(),
            lease_owner: $domainModel->getLeaseOwner(),
            lease_expires_at: $domainModel->getLeaseExpiresAt(),
            locked_at: $domainModel->getLockedAt(),
            started_at: $domainModel->getStartedAt(),
            finished_at: $domainModel->getFinishedAt(),
            last_heartbeat_at: $domainModel->getLastHeartbeatAt(),
            last_error: $domainModel->getLastError(),
            payload_json: $domainModel->getPayloadJson(),
            created_at: $domainModel->getCreatedAt(),
            updated_at: $domainModel->getUpdatedAt(),
        );
    }
}
