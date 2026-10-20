<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Service\CronOccurrenceCalculator;
use Semitexa\Scheduler\Application\Service\MisfirePolicyResolver;
use Semitexa\Scheduler\Application\Service\SchedulePlanner;
use Semitexa\Scheduler\Application\Service\TenantOccurrenceExpander;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;

/**
 * Nothing proved that due runs are computed from cron (tk-parity-proof-gaps):
 * the lease and lock tests start from runs that already exist. Here the real
 * planner, calculator and misfire policy turn a schedule into runs, against
 * in-memory repositories.
 */
final class SchedulePlannerTest extends TestCase
{
    private PlannerDefinitions $definitions;

    private PlannerRuns $runs;

    protected function setUp(): void
    {
        $this->definitions = new PlannerDefinitions();
        $this->runs = new PlannerRuns();
    }

    #[Test]
    public function every_occurrence_since_the_cursor_becomes_one_pending_run_and_the_cursor_moves(): void
    {
        $this->definitions->add($this->definition('*/5 * * * *', cursor: '2026-10-19 10:00:00'));

        $planned = $this->planner()->plan(new \DateTimeImmutable('2026-10-19 10:16:00 UTC'));

        self::assertSame(3, $planned);
        self::assertSame(['10:05', '10:10', '10:15'], $this->runs->times());
        foreach ($this->runs->all() as $run) {
            self::assertSame('pending', $run->getStatus());
            self::assertEquals($run->getScheduledFor(), $run->getAvailableAt());
        }
        self::assertSame('2026-10-19 10:15', $this->definitions->cursor('report')?->format('Y-m-d H:i'), 'from exclusive, until inclusive, cursor on the last occurrence');
    }

    #[Test]
    public function planning_again_for_the_same_moment_plans_nothing_twice(): void
    {
        $this->definitions->add($this->definition('*/5 * * * *', cursor: '2026-10-19 10:00:00'));
        $now = new \DateTimeImmutable('2026-10-19 10:16:00 UTC');

        $this->planner()->plan($now);
        // A second planner, as a second worker would be, with a cursor that did not move.
        $this->definitions->resetCursor('report', '2026-10-19 10:00:00');
        $again = $this->planner()->plan($now);

        self::assertSame(0, $again, 'the occurrence key must make planning idempotent');
        self::assertCount(3, $this->runs->all());
    }

    #[Test]
    public function a_schedule_in_a_timezone_is_planned_in_utc_on_both_sides_of_daylight_saving(): void
    {
        // 09:00 in Kyiv: UTC+2 in January, UTC+3 in July.
        $this->definitions->add($this->definition('0 9 * * *', cursor: '2026-01-15 00:00:00', timezone: 'Europe/Kyiv', key: 'winter'));
        $this->planner()->plan(new \DateTimeImmutable('2026-01-15 12:00:00 UTC'));
        // Off before July: planned again, January's schedule would catch up half a year.
        $this->definitions->findByKey('winter')?->setEnabled(false);
        $this->definitions->add($this->definition('0 9 * * *', cursor: '2026-07-15 00:00:00', timezone: 'Europe/Kyiv', key: 'summer'));
        $this->planner()->plan(new \DateTimeImmutable('2026-07-15 12:00:00 UTC'));

        self::assertSame(['07:00', '06:00'], $this->runs->times());
    }

    #[Test]
    public function the_misfire_policy_decides_what_a_long_outage_catches_up(): void
    {
        $now = new \DateTimeImmutable('2026-10-19 11:00:00 UTC');
        foreach (['skip' => 'skip', 'once' => 'run_once', 'capped' => 'catch_up'] as $key => $policy) {
            $this->definitions->add($this->definition('*/10 * * * *', cursor: '2026-10-19 10:00:00', key: $key, misfire: $policy, maxCatchUp: 2));
        }

        $this->planner()->plan($now);

        self::assertSame([], $this->runs->timesFor('skip'));
        self::assertSame(['11:00'], $this->runs->timesFor('once'), 'run once: the latest missed occurrence');
        self::assertSame(['10:50', '11:00'], $this->runs->timesFor('capped'), 'catch up, capped at the newest two');
        self::assertSame('2026-10-19 11:00', $this->definitions->cursor('skip')?->format('Y-m-d H:i'), 'a skipped backlog still moves the cursor');
    }

    /**
     * REGRESSION (review, tk-worker-runs-mail-work). With several worker
     * containers each runs scheduler:plan, in step. Between one planner's
     * check and its insert another inserted the same occurrence: the unique
     * index refused the second insert, the exception ended that planner's
     * whole run, and the schedules after it were not planned. An occurrence
     * another planner already inserted is now taken as planned.
     */
    #[Test]
    public function an_occurrence_another_planner_inserted_meanwhile_is_taken_as_planned_and_planning_goes_on(): void
    {
        $this->definitions->add($this->definition('*/5 * * * *', cursor: '2026-10-19 10:00:00', key: 'first'));
        $this->definitions->add($this->definition('*/5 * * * *', cursor: '2026-10-19 10:00:00', key: 'second'));
        $racing = new PlannerRacingRuns($this->runs, raceOnScheduleKey: 'first');

        $planned = (new SchedulePlanner($this->definitions, $racing, new CronOccurrenceCalculator(), new MisfirePolicyResolver(), new TenantOccurrenceExpander()))
            ->plan(new \DateTimeImmutable('2026-10-19 10:06:00 UTC'));

        self::assertSame(1, $planned, 'the other planner planned first; this one planned second');
        self::assertSame(['first', 'second'], array_map(static fn (ScheduledRun $r): string => (string) $r->getScheduleKey(), $this->runs->all()));
        self::assertSame('2026-10-19 10:05', $this->definitions->cursor('second')?->format('Y-m-d H:i'), 'the schedule after the race was still planned');
    }

    private function planner(): SchedulePlanner
    {
        return new SchedulePlanner($this->definitions, $this->runs, new CronOccurrenceCalculator(), new MisfirePolicyResolver(), new TenantOccurrenceExpander());
    }

    private function definition(string $cron, string $cursor, string $timezone = 'UTC', string $key = 'report', string $misfire = 'catch_up', ?int $maxCatchUp = null): ScheduleDefinition
    {
        $definition = new ScheduleDefinition();
        $definition->setScheduleKey($key);
        $definition->setJobClass('App\\Jobs\\Report');
        $definition->setCronExpression($cron);
        $definition->setTimezone($timezone);
        $definition->setPool('default');
        $definition->setOverlapPolicy('allow');
        $definition->setMisfirePolicy($misfire);
        $definition->setTenantMode('global');
        $definition->setMaxCatchUpRuns($maxCatchUp);
        $definition->setMaxAttempts(1);
        $definition->setRetryBackoffSeconds(0);
        $definition->setEnabled(true);
        $definition->setPlanningCursorAt(new \DateTimeImmutable($cursor . ' UTC'));

        return $definition;
    }
}

final class PlannerDefinitions implements ScheduleDefinitionRepositoryInterface
{
    /** @var array<string, ScheduleDefinition> */
    private array $byKey = [];

    public function add(ScheduleDefinition $definition): void
    {
        $this->byKey[$definition->getScheduleKey()] = $definition;
    }

    public function cursor(string $key): ?\DateTimeImmutable
    {
        return $this->byKey[$key]->getPlanningCursorAt();
    }

    public function resetCursor(string $key, string $at): void
    {
        $this->byKey[$key]->setPlanningCursorAt(new \DateTimeImmutable($at . ' UTC'));
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
        $this->add($entity);
    }

    public function advancePlanningCursor(string $scheduleKey, \DateTimeImmutable $cursor): void
    {
        $this->byKey[$scheduleKey]->setPlanningCursorAt($cursor);
    }
}

final class PlannerRuns implements ScheduledRunRepositoryInterface
{
    /** @var array<string, ScheduledRun> by occurrence key */
    private array $byOccurrence = [];

    /** @return list<ScheduledRun> */
    public function all(): array
    {
        return array_values($this->byOccurrence);
    }

    /** @return list<string> H:i in UTC, in planning order */
    public function times(): array
    {
        return array_map(self::utcTime(...), $this->all());
    }

    /** @return list<string> */
    public function timesFor(string $scheduleKey): array
    {
        return array_values(array_map(
            self::utcTime(...),
            array_filter($this->all(), static fn (ScheduledRun $r): bool => $r->getScheduleKey() === $scheduleKey),
        ));
    }

    private static function utcTime(ScheduledRun $run): string
    {
        $at = $run->getScheduledFor() ?? throw new \LogicException('A planned run has no scheduledFor.');

        return $at->setTimezone(new \DateTimeZone('UTC'))->format('H:i');
    }

    public function findById(string $id): ?ScheduledRun
    {
        return null;
    }

    public function findByOccurrenceKey(string $occurrenceKey): ?ScheduledRun
    {
        return $this->byOccurrence[$occurrenceKey] ?? null;
    }

    public function save(ScheduledRun $entity): void
    {
        $this->byOccurrence[$entity->getOccurrenceKey()] = $entity;
    }

    public function claimNextDue(string $pool, string $workerId, int $leaseTtlSeconds): ?string
    {
        return null;
    }

    public function renewLease(string $runId, string $workerId, int $leaseTtlSeconds): bool
    {
        return false;
    }

    public function finalizeIfOwned(ScheduledRun $run, ?string $expectedOwner): bool
    {
        return false;
    }

    public function reclaimExpiredLeases(\DateTimeImmutable $now): int
    {
        return 0;
    }
}

/** Another planner inserts the same occurrence between this one's check and its insert. */
final class PlannerRacingRuns implements ScheduledRunRepositoryInterface
{
    private bool $raced = false;

    public function __construct(private readonly PlannerRuns $inner, private readonly string $raceOnScheduleKey)
    {
    }

    public function findById(string $id): ?ScheduledRun
    {
        return $this->inner->findById($id);
    }

    public function findByOccurrenceKey(string $occurrenceKey): ?ScheduledRun
    {
        return $this->inner->findByOccurrenceKey($occurrenceKey);
    }

    public function save(ScheduledRun $entity): void
    {
        if (!$this->raced && $entity->getScheduleKey() === $this->raceOnScheduleKey) {
            $this->raced = true;
            $theirs = clone $entity;
            $this->inner->save($theirs);
            throw new \Semitexa\Orm\Exception\ConstraintViolationException("Duplicate entry for key 'uniq_scheduler_run_occurrence'", '23000', 1062);
        }
        $this->inner->save($entity);
    }

    public function claimNextDue(string $pool, string $workerId, int $leaseTtlSeconds): ?string
    {
        return $this->inner->claimNextDue($pool, $workerId, $leaseTtlSeconds);
    }

    public function renewLease(string $runId, string $workerId, int $leaseTtlSeconds): bool
    {
        return $this->inner->renewLease($runId, $workerId, $leaseTtlSeconds);
    }

    public function finalizeIfOwned(ScheduledRun $run, ?string $expectedOwner): bool
    {
        return $this->inner->finalizeIfOwned($run, $expectedOwner);
    }

    public function reclaimExpiredLeases(\DateTimeImmutable $now): int
    {
        return $this->inner->reclaimExpiredLeases($now);
    }
}
