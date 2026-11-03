<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Support;

use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;

final class InMemoryScheduleDefinitions implements ScheduleDefinitionRepositoryInterface
{
    /** @var array<string, ScheduleDefinition> */
    private array $byKey = [];

    public function setCursor(string $scheduleKey, \DateTimeImmutable $cursor): void
    {
        $this->byKey[$scheduleKey]->setPlanningCursorAt($cursor);
    }

    public function findByKey(string $scheduleKey): ?ScheduleDefinition
    {
        return $this->byKey[$scheduleKey] ?? null;
    }

    public function findAllEnabled(): array
    {
        return array_values(array_filter($this->byKey, static fn (ScheduleDefinition $d): bool => $d->isEnabled()));
    }

    public function save(ScheduleDefinition $entity): void
    {
        $this->byKey[$entity->getScheduleKey()] = $entity;
    }

    public function advancePlanningCursor(string $scheduleKey, \DateTimeImmutable $cursor): void
    {
        $this->byKey[$scheduleKey]->setPlanningCursorAt($cursor);
    }
}
