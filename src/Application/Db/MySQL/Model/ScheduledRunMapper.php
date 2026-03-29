<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;

#[AsMapper(tableModel: ScheduledRunTableModel::class, domainModel: ScheduledRun::class)]
final class ScheduledRunMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof ScheduledRunTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        return new ScheduledRun(
            id: $tableModel->id,
            sourceType: $tableModel->sourceType,
            scheduleDefinitionId: $tableModel->scheduleDefinitionId,
            scheduleKey: $tableModel->scheduleKey,
            occurrenceKey: $tableModel->occurrenceKey,
            jobClass: $tableModel->jobClass,
            tenantId: $tableModel->tenantId,
            pool: $tableModel->pool,
            lockKey: $tableModel->lockKey,
            status: $tableModel->status,
            scheduledFor: $tableModel->scheduledFor,
            availableAt: $tableModel->availableAt,
            misfiredAt: $tableModel->misfiredAt,
            attemptCount: $tableModel->attemptCount,
            maxAttempts: $tableModel->maxAttempts,
            retryBackoffSeconds: $tableModel->retryBackoffSeconds,
            leaseOwner: $tableModel->leaseOwner,
            leaseExpiresAt: $tableModel->leaseExpiresAt,
            lockedAt: $tableModel->lockedAt,
            startedAt: $tableModel->startedAt,
            finishedAt: $tableModel->finishedAt,
            lastHeartbeatAt: $tableModel->lastHeartbeatAt,
            lastError: $tableModel->lastError,
            payloadJson: $tableModel->payloadJson,
            createdAt: $tableModel->createdAt,
            updatedAt: $tableModel->updatedAt,
        );
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof ScheduledRun || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ScheduledRunTableModel(
            id: $domainModel->id,
            sourceType: $domainModel->sourceType,
            scheduleDefinitionId: $domainModel->scheduleDefinitionId,
            scheduleKey: $domainModel->scheduleKey,
            occurrenceKey: $domainModel->occurrenceKey,
            jobClass: $domainModel->jobClass,
            tenantId: $domainModel->tenantId,
            pool: $domainModel->pool,
            lockKey: $domainModel->lockKey,
            status: $domainModel->status,
            scheduledFor: $domainModel->scheduledFor,
            availableAt: $domainModel->availableAt,
            misfiredAt: $domainModel->misfiredAt,
            attemptCount: $domainModel->attemptCount,
            maxAttempts: $domainModel->maxAttempts,
            retryBackoffSeconds: $domainModel->retryBackoffSeconds,
            leaseOwner: $domainModel->leaseOwner,
            leaseExpiresAt: $domainModel->leaseExpiresAt,
            lockedAt: $domainModel->lockedAt,
            startedAt: $domainModel->startedAt,
            finishedAt: $domainModel->finishedAt,
            lastHeartbeatAt: $domainModel->lastHeartbeatAt,
            lastError: $domainModel->lastError,
            payloadJson: $domainModel->payloadJson,
            createdAt: $domainModel->createdAt,
            updatedAt: $domainModel->updatedAt,
        );
    }
}
