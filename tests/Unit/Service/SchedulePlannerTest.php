<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Scheduler\Application\Service\CronOccurrenceCalculator;
use Semitexa\Scheduler\Application\Service\MisfirePolicyResolver;
use Semitexa\Scheduler\Application\Service\SchedulePlanner;
use Semitexa\Scheduler\Application\Service\TenantOccurrenceExpander;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

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

    private PlannerLog $log;

    /** The logger found, put back: reset() would leave later tests with none (review). */
    private mixed $previousLogger = null;

    protected function setUp(): void
    {
        $this->definitions = new PlannerDefinitions();
        $this->runs = new PlannerRuns();
        $this->log = new PlannerLog();
        $this->previousLogger = (new \ReflectionProperty(StaticLoggerBridge::class, 'logger'))->getValue();
        StaticLoggerBridge::set($this->log);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(StaticLoggerBridge::class, 'logger'))->setValue(null, $this->previousLogger);
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
        // Planning resumes at 11:03: even the 11:00 occurrence is late.
        $now = new \DateTimeImmutable('2026-10-19 11:03:00 UTC');
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
     * REGRESSION (tk-scheduler-skip-misfire-drops-ontime). Skip dropped every
     * occurrence the planner found, the one due now included, so a job with
     * misfirePolicy skip never ran at all. Only an occurrence the planner is
     * late for is a misfire.
     */
    #[Test]
    public function skip_still_plans_the_occurrence_that_is_due_now(): void
    {
        $this->definitions->add($this->definition('*/5 * * * *', cursor: '2026-10-19 10:00:00', misfire: 'skip'));

        $planned = $this->planner()->plan(new \DateTimeImmutable('2026-10-19 10:05:20 UTC'));

        self::assertSame(1, $planned);
        self::assertSame(['10:05'], $this->runs->timesFor('report'));
    }

    /** After an outage that ends on an occurrence, skip drops the backlog and keeps the one due now. */
    #[Test]
    public function skip_after_an_outage_plans_only_the_occurrence_due_now(): void
    {
        $this->definitions->add($this->definition('*/10 * * * *', cursor: '2026-10-19 10:00:00', misfire: 'skip'));

        $this->planner()->plan(new \DateTimeImmutable('2026-10-19 11:00:30 UTC'));

        self::assertSame(['11:00'], $this->runs->timesFor('report'));
        self::assertSame('2026-10-19 11:00', $this->definitions->cursor('report')?->format('Y-m-d H:i'));
    }

    /** On time means at most ON_TIME_SECONDS (120 s) late: 120 s is kept, 121 s is a misfire. */
    #[Test]
    public function an_occurrence_120_seconds_late_is_on_time_and_121_seconds_late_is_missed(): void
    {
        $this->definitions->add($this->definition('*/5 * * * *', cursor: '2026-10-19 10:00:00', key: 'kept', misfire: 'skip'));
        $this->planner()->plan(new \DateTimeImmutable('2026-10-19 10:07:00 UTC'));
        $this->definitions->add($this->definition('*/5 * * * *', cursor: '2026-10-19 10:00:00', key: 'dropped', misfire: 'skip'));
        $this->definitions->findByKey('kept')?->setEnabled(false);
        $this->planner()->plan(new \DateTimeImmutable('2026-10-19 10:07:01 UTC'));

        self::assertSame(['10:05'], $this->runs->timesFor('kept'));
        self::assertSame([], $this->runs->timesFor('dropped'));
    }

    /**
     * A run lost to a restart gap just over the threshold looked exactly like
     * a schedule with nothing due. What the misfire policy drops is now said,
     * on the planner's output and in the log, with how late it was.
     */
    #[Test]
    public function an_occurrence_the_misfire_policy_drops_is_named_with_how_late_it_was(): void
    {
        $this->definitions->add($this->definition('*/5 * * * *', cursor: '2026-10-19 10:00:00', misfire: 'skip'));
        $output = new BufferedOutput();

        $this->planner()->plan(new \DateTimeImmutable('2026-10-19 10:07:01 UTC'), $output);

        $line = "Schedule 'report' (misfirePolicy skip) dropped 1 missed occurrence: 2026-10-19 10:05:00, 121 s late.";
        self::assertSame("  {$line}\n", $output->fetch());
        self::assertSame([['warning', $line, [
            'schedule_key' => 'report',
            'misfire_policy' => 'skip',
            'dropped' => 1,
            'oldest' => '2026-10-19T10:05:00+00:00',
            'oldest_late_seconds' => 121,
            'newest' => '2026-10-19T10:05:00+00:00',
            'newest_late_seconds' => 121,
            '_channel' => 'scheduler',
        ]]], $this->log->entries);
    }

    /** A long outage is one entry per schedule, not one per occurrence. */
    #[Test]
    public function a_long_outage_is_said_once_per_schedule_with_its_oldest_and_newest_occurrence(): void
    {
        $this->definitions->add($this->definition('*/10 * * * *', cursor: '2026-10-19 10:00:00', misfire: 'skip'));
        $output = new BufferedOutput();

        $this->planner()->plan(new \DateTimeImmutable('2026-10-19 11:00:30 UTC'), $output);

        self::assertSame(['11:00'], $this->runs->timesFor('report'));
        self::assertSame([
            "  Schedule 'report' (misfirePolicy skip) dropped 5 missed occurrences: 2026-10-19 10:10:00 (3030 s late) to 2026-10-19 10:50:00 (630 s late).",
            '  Planned  for report at 2026-10-19 11:00:00',
        ], explode("\n", rtrim($output->fetch(), "\n")));
        self::assertCount(1, $this->log->entries);
    }

    /** Nothing dropped, nothing said. */
    #[Test]
    public function planning_on_time_says_nothing_about_misfires(): void
    {
        $this->definitions->add($this->definition('*/5 * * * *', cursor: '2026-10-19 10:00:00', misfire: 'skip'));

        $this->planner()->plan(new \DateTimeImmutable('2026-10-19 10:05:20 UTC'));

        self::assertSame([], $this->log->entries);
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

    #[Test]
    public function one_broken_schedule_does_not_stop_the_others(): void
    {
        // A policy typo failed the whole plan, and every schedule after it in
        // key order stopped running.
        $broken = $this->definition('*/5 * * * *', '2026-10-21 10:00', key: 'a-broken');
        $broken->setOverlapPolicy('Skip');
        $this->definitions->add($broken);
        $this->definitions->add($this->definition('*/5 * * * *', '2026-10-21 10:00', key: 'b-fine'));

        $this->planner()->plan(new \DateTimeImmutable('2026-10-21 10:10 UTC'));

        self::assertNotSame([], $this->runs->timesFor('b-fine'), 'a schedule after the broken one was not planned');
    }

    #[Test]
    public function an_explicit_tenant_schedule_is_refused_not_run_as_global(): void
    {
        $explicit = $this->definition('*/5 * * * *', '2026-10-21 10:00', key: 'explicit');
        $explicit->setTenantMode('explicit_tenant');
        $this->definitions->add($explicit);
        $output = new BufferedOutput();

        $this->planner()->plan(new \DateTimeImmutable('2026-10-21 10:10 UTC'), $output);

        self::assertSame([], $this->runs->timesFor('explicit'));
        self::assertStringContainsString('explicit_tenant', $output->fetch());
    }

    #[Test]
    public function a_key_the_code_no_longer_declares_is_passed_over(): void
    {
        $this->definitions->add($this->definition('*/5 * * * *', '2026-10-21 10:00', key: 'old-name'));
        $this->definitions->add($this->definition('*/5 * * * *', '2026-10-21 10:00', key: 'new-name'));

        $this->planner()->plan(new \DateTimeImmutable('2026-10-21 10:10 UTC'), null, ['new-name']);

        self::assertSame([], $this->runs->timesFor('old-name'));
        self::assertNotSame([], $this->runs->timesFor('new-name'));
    }

    #[Test]
    public function discovery_that_found_nothing_does_not_stop_every_schedule(): void
    {
        $this->definitions->add($this->definition('*/5 * * * *', '2026-10-21 10:00', key: 'kept'));

        $this->planner()->plan(new \DateTimeImmutable('2026-10-21 10:10 UTC'), null, []);

        self::assertNotSame([], $this->runs->timesFor('kept'));
    }

    #[Test]
    public function a_bad_timezone_does_not_stop_the_others(): void
    {
        $broken = $this->definition('*/5 * * * *', '2026-10-21 10:00', timezone: 'Europe/Nowhere', key: 'a-broken');
        $this->definitions->add($broken);
        $this->definitions->add($this->definition('*/5 * * * *', '2026-10-21 10:00', key: 'b-fine'));

        $this->planner()->plan(new \DateTimeImmutable('2026-10-21 10:10 UTC'));

        self::assertNotSame([], $this->runs->timesFor('b-fine'));
    }

    #[Test]
    public function a_schedule_whose_job_class_is_gone_is_passed_over_and_left_enabled(): void
    {
        // Every run of it failed. It is not disabled: the class may be back
        // after the next deploy, and the schedule with it.
        $gone = $this->definition('*/5 * * * *', '2026-10-21 10:00', key: 'gone');
        $gone->setJobClass('App\\Jobs\\DeletedLongAgo');
        $this->definitions->add($gone);

        $this->planner()->plan(new \DateTimeImmutable('2026-10-21 10:10 UTC'));

        self::assertSame([], $this->runs->timesFor('gone'));
        self::assertTrue($gone->isEnabled());
    }

    #[Test]
    public function an_overlap_typo_is_refused_before_any_occurrence_is_reported_dropped(): void
    {
        // The misfire policy ran first and logged "dropped N missed
        // occurrences", N growing each minute the cursor stayed put.
        $typo = $this->definition('*/5 * * * *', '2026-10-21 08:00', key: 'typo', misfire: 'skip');
        $typo->setOverlapPolicy('Skip');
        $this->definitions->add($typo);
        $output = new BufferedOutput();

        $this->planner()->plan(new \DateTimeImmutable('2026-10-21 10:10 UTC'), $output);

        $said = $output->fetch();
        self::assertStringContainsString('typo', $said);
        self::assertStringNotContainsString('dropped', $said);
    }

    #[Test]
    public function a_cron_that_never_matches_is_passed_over_and_the_schedules_after_it_still_plan(): void
    {
        // 31 February parses; the library throws a bare RuntimeException at
        // the first calculation, which ended the whole plan.
        $this->definitions->add($this->definition('0 0 31 2 *', '2026-10-21 10:00', key: 'a-impossible'));
        $this->definitions->add($this->definition('*/5 * * * *', '2026-10-21 10:00', key: 'b-fine'));
        $output = new BufferedOutput();

        $this->planner()->plan(new \DateTimeImmutable('2026-10-21 10:10 UTC'), $output);

        self::assertSame(['10:05', '10:10'], $this->runs->timesFor('b-fine'));
        self::assertStringContainsString('a-impossible', $output->fetch());
    }

    #[Test]
    public function a_job_at_a_time_of_day_runs_once_when_the_clocks_go_back(): void
    {
        // Kyiv, 25 October 2026: 04:00 EEST becomes 03:00 EET, so 03:30
        // happens at 00:30Z and again at 01:30Z. Planned a minute at a time,
        // as the worker does, the second one lies in a later pass.
        $this->definitions->add($this->definition('30 3 * * *', '2026-10-24 23:00', timezone: 'Europe/Kyiv', key: 'daily'));
        $this->definitions->add($this->definition('0 * * * *', '2026-10-24 23:00', timezone: 'Europe/Kyiv', key: 'hourly'));
        $this->definitions->add($this->definition('0-59/30 3 * * *', '2026-10-24 23:00', timezone: 'Europe/Kyiv', key: 'stepped'));

        $at = new \DateTimeImmutable('2026-10-24 23:00 UTC');
        for ($i = 0; $i < 180; $i++) {
            $at = $at->modify('+1 minute');
            $this->planner()->plan($at);
        }

        self::assertSame(['00:30'], $this->runs->timesFor('daily'));
        self::assertSame(['00:00', '01:00', '02:00'], $this->runs->timesFor('hourly'), 'an hourly job keeps both 03:00 ticks');
        self::assertSame(['00:00', '00:30', '01:00', '01:30'], $this->runs->timesFor('stepped'), 'a step keeps the repeated hour\'s ticks');
    }

    private function planner(): SchedulePlanner
    {
        return new SchedulePlanner($this->definitions, $this->runs, new CronOccurrenceCalculator(), new MisfirePolicyResolver(), new TenantOccurrenceExpander());
    }

    private function definition(string $cron, string $cursor, string $timezone = 'UTC', string $key = 'report', string $misfire = 'catch_up', ?int $maxCatchUp = null): ScheduleDefinition
    {
        $definition = new ScheduleDefinition();
        $definition->setScheduleKey($key);
        $definition->setJobClass(PlannerFixtureJob::class);
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

final class PlannerLog implements LoggerInterface
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $entries = [];

    public function error(string $message, array $context = []): void
    {
        $this->entries[] = ['error', $message, $context];
    }

    public function critical(string $message, array $context = []): void
    {
        $this->entries[] = ['critical', $message, $context];
    }

    public function warning(string $message, array $context = []): void
    {
        $this->entries[] = ['warning', $message, $context];
    }

    public function info(string $message, array $context = []): void
    {
        $this->entries[] = ['info', $message, $context];
    }

    public function notice(string $message, array $context = []): void
    {
        $this->entries[] = ['notice', $message, $context];
    }

    public function debug(string $message, array $context = []): void
    {
        $this->entries[] = ['debug', $message, $context];
    }
}

final class PlannerFixtureJob
{
}
