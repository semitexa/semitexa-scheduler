<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Scheduler\Domain\Model\SchedulerLock;

#[AsMapper(resourceModel: SchedulerLockTableModel::class, domainModel: SchedulerLock::class)]
final class SchedulerLockMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof SchedulerLockTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        return new SchedulerLock(
            id: $tableModel->id,
            lockKey: $tableModel->lockKey,
            runId: $tableModel->runId,
            workerId: $tableModel->workerId,
            acquiredAt: $tableModel->acquiredAt,
            expiresAt: $tableModel->expiresAt,
            createdAt: $tableModel->createdAt,
            updatedAt: $tableModel->updatedAt,
        );
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof SchedulerLock || throw new \InvalidArgumentException('Unexpected domain model.');

        return new SchedulerLockTableModel(
            id: $domainModel->id,
            lockKey: $domainModel->lockKey,
            runId: $domainModel->runId,
            workerId: $domainModel->workerId,
            acquiredAt: $domainModel->acquiredAt,
            expiresAt: $domainModel->expiresAt,
            createdAt: $domainModel->createdAt,
            updatedAt: $domainModel->updatedAt,
        );
    }
}
