<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;

#[AsMapper(tableModel: ScheduleDefinitionTableModel::class, domainModel: ScheduleDefinition::class)]
final class ScheduleDefinitionMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof ScheduleDefinitionTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        return new ScheduleDefinition(
            id: $tableModel->id,
            scheduleKey: $tableModel->scheduleKey,
            jobClass: $tableModel->jobClass,
            cronExpression: $tableModel->cronExpression,
            timezone: $tableModel->timezone,
            pool: $tableModel->pool,
            overlapPolicy: $tableModel->overlapPolicy,
            misfirePolicy: $tableModel->misfirePolicy,
            tenantMode: $tableModel->tenantMode,
            maxCatchUpRuns: $tableModel->maxCatchUpRuns,
            maxAttempts: $tableModel->maxAttempts,
            retryBackoffSeconds: $tableModel->retryBackoffSeconds,
            enabled: $tableModel->enabled,
            planningCursorAt: $tableModel->planningCursorAt,
            lastPlannedAt: $tableModel->lastPlannedAt,
            payloadTemplateJson: $tableModel->payloadTemplateJson,
            createdAt: $tableModel->createdAt,
            updatedAt: $tableModel->updatedAt,
        );
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof ScheduleDefinition || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ScheduleDefinitionTableModel(
            id: $domainModel->id,
            scheduleKey: $domainModel->scheduleKey,
            jobClass: $domainModel->jobClass,
            cronExpression: $domainModel->cronExpression,
            timezone: $domainModel->timezone,
            pool: $domainModel->pool,
            overlapPolicy: $domainModel->overlapPolicy,
            misfirePolicy: $domainModel->misfirePolicy,
            tenantMode: $domainModel->tenantMode,
            maxCatchUpRuns: $domainModel->maxCatchUpRuns,
            maxAttempts: $domainModel->maxAttempts,
            retryBackoffSeconds: $domainModel->retryBackoffSeconds,
            enabled: $domainModel->enabled,
            planningCursorAt: $domainModel->planningCursorAt,
            lastPlannedAt: $domainModel->lastPlannedAt,
            payloadTemplateJson: $domainModel->payloadTemplateJson,
            createdAt: $domainModel->createdAt,
            updatedAt: $domainModel->updatedAt,
        );
    }
}
