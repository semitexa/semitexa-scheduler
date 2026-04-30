<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Scheduler\Domain\Model\SchedulerLock;

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

        $resource = new SchedulerLockResource();
        $resource->id = $domainModel->id;
        $resource->lock_key = $domainModel->lockKey;
        $resource->run_id = $domainModel->runId;
        $resource->worker_id = $domainModel->workerId;
        $resource->acquired_at = $domainModel->acquiredAt;
        $resource->expires_at = $domainModel->expiresAt;
        $resource->created_at = $domainModel->createdAt;
        $resource->updated_at = $domainModel->updatedAt;
        return $resource;
    }
}
