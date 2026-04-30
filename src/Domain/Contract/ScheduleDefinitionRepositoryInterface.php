<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Contract;

use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;

interface ScheduleDefinitionRepositoryInterface
{
    public function findByKey(string $scheduleKey): ?ScheduleDefinition;

    /** @return list<ScheduleDefinition> */
    public function findAllEnabled(): array;

    public function save(ScheduleDefinition $entity): void;

    public function advancePlanningCursor(string $scheduleKey, \DateTimeImmutable $cursor): void;
}
