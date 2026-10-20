<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Scheduler\Application\Service\ScheduleDefinitionRegistry;
use Semitexa\Scheduler\Attribute\AsScheduledJob;
use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;

/**
 * REGRESSION (tk-worker-runs-mail-work). The scheduler builds a job from its
 * class name through the container, which builds services only. A job that
 * was not one was still scheduled, and every run of it failed for good with
 * "not found". Six of the demo's jobs were like that, found the first time a
 * worker ran scheduler:work. sync() now names such a job as a problem and
 * does not schedule it.
 */
final class ScheduleDefinitionRegistrySyncTest extends TestCase
{
    #[Test]
    public function a_job_the_container_cannot_build_is_reported_and_not_scheduled(): void
    {
        $repository = new SyncFixtureRepository();
        $registry = $this->registry([SyncFixtureServiceJob::class, SyncFixturePlainJob::class], $repository);

        $problems = $registry->sync();

        self::assertSame(['fixture.service'], array_keys($repository->saved));
        self::assertSame([
            "Scheduled job '" . SyncFixturePlainJob::class . "' (fixture.plain) is not a service, so the scheduler cannot build it: mark it #[AsService].",
        ], $problems);
    }

    /** @param list<class-string> $classes */
    private function registry(array $classes, SyncFixtureRepository $repository): ScheduleDefinitionRegistry
    {
        $discovery = (new \ReflectionClass(SyncFixtureDiscovery::class))->newInstanceWithoutConstructor();
        $discovery->classes = $classes;
        $registry = new ScheduleDefinitionRegistry();
        (new \ReflectionProperty($registry, 'repository'))->setValue($registry, $repository);
        (new \ReflectionProperty($registry, 'classDiscovery'))->setValue($registry, $discovery);

        return $registry;
    }
}

final class SyncFixtureDiscovery extends ClassDiscovery
{
    /** @var list<class-string> */
    public array $classes = [];

    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $this->classes;
    }
}

final class SyncFixtureRepository implements ScheduleDefinitionRepositoryInterface
{
    /** @var array<string, ScheduleDefinition> */
    public array $saved = [];

    public function findByKey(string $scheduleKey): ?ScheduleDefinition
    {
        return $this->saved[$scheduleKey] ?? null;
    }

    public function findAllEnabled(): array
    {
        return array_values($this->saved);
    }

    public function save(ScheduleDefinition $entity): void
    {
        $this->saved[$entity->getScheduleKey()] = $entity;
    }

    public function advancePlanningCursor(string $scheduleKey, \DateTimeImmutable $cursor): void
    {
    }
}

#[AsService]
#[AsScheduledJob(key: 'fixture.service', cronExpression: '*/5 * * * *')]
final class SyncFixtureServiceJob implements ScheduledJobInterface
{
    public function handle(ScheduledJobContext $context): void
    {
    }
}

#[AsScheduledJob(key: 'fixture.plain', cronExpression: '*/5 * * * *')]
final class SyncFixturePlainJob implements ScheduledJobInterface
{
    public function handle(ScheduledJobContext $context): void
    {
    }
}
