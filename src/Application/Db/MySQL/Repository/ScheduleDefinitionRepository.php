<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerScheduleDefinitionResource;
use Semitexa\Scheduler\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;

#[SatisfiesRepositoryContract(of: ScheduleDefinitionRepositoryInterface::class)]
class ScheduleDefinitionRepository extends AbstractRepository implements ScheduleDefinitionRepositoryInterface
{
    public function __construct(
        private readonly DatabaseAdapterInterface $db,
        ?\Semitexa\Orm\Hydration\StreamingHydrator $hydrator = null,
    ) {
        parent::__construct($db, $hydrator);
    }

    protected function getResourceClass(): string
    {
        return SchedulerScheduleDefinitionResource::class;
    }

    public function findByKey(string $scheduleKey): ?ScheduleDefinition
    {
        $resource = $this->select()
            ->where('schedule_key', '=', $scheduleKey)
            ->fetchOneAsResource();
        return $resource?->toDomain();
    }

    public function findAllEnabled(): array
    {
        $resources = $this->select()
            ->where('enabled', '=', 1)
            ->fetchAllAsResource();
        return array_map(fn($r) => $r->toDomain(), $resources);
    }

    public function save(ScheduleDefinition $definition): void
    {
        $resource = SchedulerScheduleDefinitionResource::fromDomain($definition);
        parent::save($resource);
        $definition->id = $resource->id;
    }

    public function advancePlanningCursor(string $scheduleKey, \DateTimeImmutable $cursor): void
    {
        $resource = $this->select()
            ->where('schedule_key', '=', $scheduleKey)
            ->fetchOneAsResource();
        if ($resource === null) {
            return;
        }
        $resource->planning_cursor_at = $cursor;
        $resource->last_planned_at = new \DateTimeImmutable();
        parent::save($resource);
    }
}
