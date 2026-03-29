<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Scheduler\Application\Db\MySQL\Model\ScheduleDefinitionTableModel;
use Semitexa\Scheduler\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;

#[SatisfiesRepositoryContract(of: ScheduleDefinitionRepositoryInterface::class)]
final class ScheduleDefinitionRepository implements ScheduleDefinitionRepositoryInterface
{
    #[InjectAsReadonly]
    protected ?OrmManager $orm = null;

    private ?DomainRepository $repository = null;

    public function findByKey(string $scheduleKey): ?ScheduleDefinition
    {
        /** @var ScheduleDefinition|null */
        return $this->repository()->query()
            ->where(ScheduleDefinitionTableModel::column('scheduleKey'), Operator::Equals, $scheduleKey)
            ->fetchOneAs(ScheduleDefinition::class, $this->orm()->getMapperRegistry());
    }

    public function findAllEnabled(): array
    {
        /** @var list<ScheduleDefinition> */
        return $this->repository()->query()
            ->where(ScheduleDefinitionTableModel::column('enabled'), Operator::Equals, true)
            ->orderBy(ScheduleDefinitionTableModel::column('scheduleKey'), Direction::Asc)
            ->fetchAllAs(ScheduleDefinition::class, $this->orm()->getMapperRegistry());
    }

    public function save(ScheduleDefinition $entity): void
    {
        $persisted = $entity->id === ''
            ? $this->repository()->insert($entity)
            : $this->repository()->update($entity);

        $this->copyIntoMutableDomain($persisted, $entity);
    }

    public function advancePlanningCursor(string $scheduleKey, \DateTimeImmutable $cursor): void
    {
        $definition = $this->findByKey($scheduleKey);
        if ($definition === null) {
            return;
        }

        $definition->planningCursorAt = $cursor;
        $definition->lastPlannedAt = new \DateTimeImmutable();
        $this->repository()->update($definition);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            ScheduleDefinitionTableModel::class,
            ScheduleDefinition::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

    private function copyIntoMutableDomain(object $source, ScheduleDefinition $target): void
    {
        $source instanceof ScheduleDefinition || throw new \InvalidArgumentException('Unexpected persisted domain model.');

        foreach (get_object_vars($source) as $property => $value) {
            $target->{$property} = $value;
        }
    }
}
