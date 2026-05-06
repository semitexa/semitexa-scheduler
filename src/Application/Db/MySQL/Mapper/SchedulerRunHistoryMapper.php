<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunHistoryResource;

#[AsMapper(resourceModel: SchedulerRunHistoryResource::class, domainModel: SchedulerRunHistoryResource::class)]
final class SchedulerRunHistoryMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof SchedulerRunHistoryResource || throw new \InvalidArgumentException('Unexpected resource model.');
        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof SchedulerRunHistoryResource || throw new \InvalidArgumentException('Unexpected domain model.');
        return clone $domainModel;
    }
}
