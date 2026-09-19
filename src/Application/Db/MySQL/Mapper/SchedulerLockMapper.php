<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Scheduler\Domain\Model\SchedulerLock;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerLockResource;

#[AsMapper(resourceModel: SchedulerLockResource::class, domainModel: SchedulerLock::class)]
final class SchedulerLockMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof SchedulerLockResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new SchedulerLock(
            id: $resourceModel->id,
            lockKey: $resourceModel->lock_key,
            runId: $resourceModel->run_id,
            workerId: $resourceModel->worker_id,
            acquiredAt: $resourceModel->acquired_at,
            expiresAt: $resourceModel->expires_at,
            createdAt: $resourceModel->created_at,
            updatedAt: $resourceModel->updated_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof SchedulerLock || throw new \InvalidArgumentException('Unexpected domain model.');

        return new SchedulerLockResource(
            id: $domainModel->getId(),
            lock_key: $domainModel->getLockKey(),
            run_id: $domainModel->getRunId(),
            worker_id: $domainModel->getWorkerId(),
            acquired_at: $domainModel->getAcquiredAt(),
            expires_at: $domainModel->getExpiresAt(),
            created_at: $domainModel->getCreatedAt(),
            updated_at: $domainModel->getUpdatedAt(),
        );
    }
}
