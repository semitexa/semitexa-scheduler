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

    /** Two worker containers sync at once: the one that loses the insert writes onto the other's row. */
    #[Test]
    public function a_schedule_another_worker_inserted_meanwhile_is_updated_not_a_failure(): void
    {
        $repository = new SyncFixtureRepository();
        $repository->raceOnFirstInsert = true;

        $problems = $this->registry([SyncFixtureServiceJob::class], $repository)->sync();

        self::assertSame([], $problems);
        self::assertSame('their-row', $repository->saved['fixture.service']->getId(), 'the other worker\'s row, updated, not a second one');
        self::assertSame('*/5 * * * *', $repository->saved['fixture.service']->getCronExpression());
        self::assertSame(SyncFixtureServiceJob::class, $repository->saved['fixture.service']->getJobClass());
    }

    #[Test]
    public function a_schedule_whose_job_class_is_gone_is_said_and_left_enabled(): void
    {
        // It kept being planned, and every run failed; the planner now passes
        // it over. Disabling it here switched it off for good when the class
        // was missing only for a moment.
        $repository = new SyncFixtureRepository();
        $gone = new ScheduleDefinition();
        $gone->setId('gone-row');
        $gone->setScheduleKey('fixture.gone');
        $gone->setJobClass('App\\Jobs\\DeletedLongAgo');
        $gone->setEnabled(true);
        $repository->saved['fixture.gone'] = $gone;

        $problems = $this->registry([SyncFixtureServiceJob::class], $repository)->sync();

        self::assertTrue($repository->saved['fixture.gone']->isEnabled());
        self::assertStringContainsString('fixture.gone', implode(' ', $problems));
        self::assertTrue($repository->saved['fixture.service']->isEnabled(), 'a schedule whose class exists is untouched');
    }

    #[Test]
    public function a_renamed_key_is_said_and_left_as_it_was(): void
    {
        // The old row kept planning beside the renamed one: the job ran twice
        // per occurrence. The planner passes it over; it is not disabled, so a
        // rollback to the old name plans it again.
        $repository = new SyncFixtureRepository();
        $old = new ScheduleDefinition();
        $old->setId('old-row');
        $old->setScheduleKey('fixture.old-name');
        $old->setJobClass(SyncFixtureServiceJob::class);
        $old->setEnabled(true);
        $repository->saved['fixture.old-name'] = $old;

        $registry = $this->registry([SyncFixtureServiceJob::class], $repository);
        $problems = $registry->sync();

        self::assertStringContainsString('fixture.old-name', implode(' ', $problems));
        self::assertTrue($repository->saved['fixture.old-name']->isEnabled());
        self::assertSame(['fixture.service'], $registry->declaredKeys());
    }

    #[Test]
    public function a_key_on_a_job_the_sync_refuses_is_still_declared(): void
    {
        // Its row is kept as it was, not reported as orphaned.
        $registry = $this->registry([SyncFixtureServiceJob::class, SyncFixturePlainJob::class], new SyncFixtureRepository());

        self::assertSame(['fixture.service', 'fixture.plain'], $registry->declaredKeys());
    }

    #[Test]
    public function a_malformed_attribute_is_reported_and_the_rest_still_sync(): void
    {
        // It threw out of the sync, and nothing was planned on any pass.
        $repository = new SyncFixtureRepository();

        $problems = $this->registry([SyncFixtureMalformedJob::class, SyncFixtureServiceJob::class], $repository)->sync();

        self::assertSame(['fixture.service'], array_keys($repository->saved));
        self::assertStringContainsString(SyncFixtureMalformedJob::class, implode(' ', $problems));
    }

    #[Test]
    public function a_job_the_container_cannot_build_is_declared_but_not_planned(): void
    {
        // Its row was kept and planned, and every run failed when the
        // container could not build the job.
        $registry = $this->registry([SyncFixtureServiceJob::class, SyncFixturePlainJob::class], new SyncFixtureRepository());

        self::assertSame(['fixture.service'], $registry->plannableKeys());
    }

    #[Test]
    public function an_argument_that_does_not_evaluate_is_reported_and_the_plan_goes_on(): void
    {
        // sync() caught it; declaredKeys() evaluated the same arguments again
        // and threw, and scheduler:plan planned nothing.
        $repository = new SyncFixtureRepository();
        $registry = $this->registry([SyncFixtureUndefinedConstantJob::class, SyncFixtureServiceJob::class], $repository);

        $problems = $registry->sync();

        self::assertSame(['fixture.service'], array_keys($repository->saved));
        self::assertSame(
            ["Scheduled job '" . SyncFixtureUndefinedConstantJob::class . "' has a malformed #[AsScheduledJob]: Undefined constant self::MISSING"],
            $problems,
        );
        self::assertSame(['fixture.service'], $registry->declaredKeys());
        self::assertSame(['fixture.service'], $registry->plannableKeys());
    }

    #[Test]
    public function jobs_found_without_a_usable_key_declare_none_and_no_jobs_found_declare_nothing_known(): void
    {
        // Both were an empty list, and the planner took a misspelled `key:`
        // for broken discovery: it planned the old row the job no longer names.
        $keyless = $this->registry([SyncFixtureKeylessJob::class], new SyncFixtureRepository());
        $none = $this->registry([], new SyncFixtureRepository());

        self::assertSame([], $keyless->declaredKeys());
        self::assertSame([], $keyless->plannableKeys());
        self::assertNull($none->declaredKeys());
        self::assertNull($none->plannableKeys());
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

    public bool $raceOnFirstInsert = false;

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
        $key = $entity->getScheduleKey();
        if ($this->raceOnFirstInsert && !isset($this->saved[$key])) {
            $this->raceOnFirstInsert = false;
            $theirs = new ScheduleDefinition();
            $theirs->setId('their-row');
            $theirs->setScheduleKey($key);
            $this->saved[$key] = $theirs;
            throw self::duplicate();
        }
        // As the unique index on schedule_key: a new row whose key is taken is refused, every time.
        if ($entity->getId() === '' && isset($this->saved[$key])) {
            throw self::duplicate();
        }
        $this->saved[$key] = $entity;
    }

    private static function duplicate(): \Semitexa\Orm\Exception\ConstraintViolationException
    {
        return new \Semitexa\Orm\Exception\ConstraintViolationException("Duplicate entry for key 'uniq_scheduler_schedule_key'", '23000', 1062);
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

#[AsService]
#[AsScheduledJob(key: 'fixture.malformed', cron: '*/5 * * * *')]
final class SyncFixtureMalformedJob implements ScheduledJobInterface
{
    public function handle(ScheduledJobContext $context): void
    {
    }
}

#[AsService]
// @phpstan-ignore-next-line classConstant.notFound — the defect under test: a constant that does not exist
#[AsScheduledJob(key: 'fixture.undefined', cronExpression: self::MISSING)]
final class SyncFixtureUndefinedConstantJob implements ScheduledJobInterface
{
    public function handle(ScheduledJobContext $context): void
    {
    }
}

#[AsService]
// @phpstan-ignore-next-line argument.missing — the defect under test: no key
#[AsScheduledJob(cronExpression: '*/5 * * * *')]
final class SyncFixtureKeylessJob implements ScheduledJobInterface
{
    public function handle(ScheduledJobContext $context): void
    {
    }
}
