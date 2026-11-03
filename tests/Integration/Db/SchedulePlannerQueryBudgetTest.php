<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Integration\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\ScheduledRunRepository;
use Semitexa\Scheduler\Application\Service\CronOccurrenceCalculator;
use Semitexa\Scheduler\Application\Service\MisfirePolicyResolver;
use Semitexa\Scheduler\Application\Service\SchedulePlanner;
use Semitexa\Scheduler\Application\Service\TenantOccurrenceExpander;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
use Semitexa\Orm\OrmManager;
use Semitexa\Scheduler\Tests\Support\InMemoryScheduleDefinitions;
use Semitexa\Scheduler\Tests\Support\RepositoryHarness;

/**
 * What one planning pass costs the database, through the real run
 * repository on SQLite. The planner used to read each occurrence's key
 * before inserting it: a catch-up of N occurrences was N SELECTs before its
 * N INSERTs. The keys already planned are now read per pass, in chunks.
 */
final class SchedulePlannerQueryBudgetTest extends TestCase
{
    private OrmManager $orm;

    private InMemoryScheduleDefinitions $definitions;

    private SchedulePlanner $planner;

    protected function setUp(): void
    {
        $this->orm = RepositoryHarness::sqliteOrm();
        $sqlite = $this->orm->getAdapter();
        $sqlite->execute(
            'CREATE TABLE scheduler_runs (
                id BLOB PRIMARY KEY,
                source_type TEXT NOT NULL,
                schedule_definition_id BLOB,
                schedule_key TEXT,
                occurrence_key TEXT,
                job_class TEXT NOT NULL,
                tenant_id TEXT,
                pool TEXT NOT NULL,
                lock_key TEXT,
                status TEXT NOT NULL,
                scheduled_for TEXT,
                available_at TEXT,
                misfired_at TEXT,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 1,
                retry_backoff_seconds INTEGER NOT NULL DEFAULT 0,
                lease_owner TEXT,
                lease_expires_at TEXT,
                locked_at TEXT,
                started_at TEXT,
                finished_at TEXT,
                last_heartbeat_at TEXT,
                last_error TEXT,
                payload_json TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
        );
        $sqlite->execute('CREATE UNIQUE INDEX uniq_scheduler_run_occurrence ON scheduler_runs (occurrence_key)');

        $runs = RepositoryHarness::repository(ScheduledRunRepository::class, $this->orm);
        $this->definitions = new InMemoryScheduleDefinitions();
        $this->planner = new SchedulePlanner($this->definitions, $runs, new CronOccurrenceCalculator(), new MisfirePolicyResolver(), new TenantOccurrenceExpander());
    }

    #[Test]
    public function a_catch_up_of_120_occurrences_reads_the_planned_keys_once_not_once_per_occurrence(): void
    {
        $this->definitions->save($this->everyMinute(cursor: '2026-11-03 08:00:00'));

        $this->orm->enableQueryLog();
        $planned = $this->planner->plan(new \DateTimeImmutable('2026-11-03 10:00:00 UTC'));
        $sql = $this->statements();

        self::assertSame(120, $planned);
        self::assertSame(120, self::statementsStarting($sql, 'INSERT'));
        self::assertSame(1, self::statementsStarting($sql, 'SELECT'));
    }

    #[Test]
    public function the_occurrences_are_planned_in_batches_so_neither_memory_nor_a_statement_grows_with_the_outage(): void
    {
        // 1 200 occurrences: three batches of at most 500.
        $this->definitions->save($this->everyMinute(cursor: '2026-11-02 14:00:00'));

        $this->orm->enableQueryLog();
        $planned = $this->planner->plan(new \DateTimeImmutable('2026-11-03 10:00:00 UTC'));

        $sql = $this->statements();

        self::assertSame(1200, $planned);
        self::assertSame(3, self::statementsStarting($sql, 'SELECT'));
        // A batch is read, then inserted, before the next is expanded: the
        // pass never holds more than 500 occurrences.
        $selects = array_keys(array_filter($sql, static fn (string $q): bool => str_starts_with($q, 'SELECT')));
        self::assertSame([0, 501, 1002], array_values($selects));
    }

    #[Test]
    public function a_second_pass_over_the_same_window_plans_nothing_and_writes_nothing(): void
    {
        $this->definitions->save($this->everyMinute(cursor: '2026-11-03 08:00:00'));
        $now = new \DateTimeImmutable('2026-11-03 10:00:00 UTC');
        $this->planner->plan($now);

        // A second worker's planner, whose cursor did not move.
        $this->definitions->setCursor('minutely', new \DateTimeImmutable('2026-11-03 08:00:00 UTC'));
        $this->orm->enableQueryLog();
        $again = $this->planner->plan($now);
        $sql = $this->statements();

        self::assertSame(0, $again);
        self::assertSame(0, self::statementsStarting($sql, 'INSERT'), 'every key was found planned, none was inserted');
        self::assertSame(1, self::statementsStarting($sql, 'SELECT'));
    }

    #[Test]
    public function only_the_occurrences_not_yet_planned_are_inserted(): void
    {
        $this->definitions->save($this->everyMinute(cursor: '2026-11-03 08:00:00'));
        $this->planner->plan(new \DateTimeImmutable('2026-11-03 09:00:00 UTC'));

        $this->definitions->setCursor('minutely', new \DateTimeImmutable('2026-11-03 08:00:00 UTC'));
        $this->orm->enableQueryLog();
        $planned = $this->planner->plan(new \DateTimeImmutable('2026-11-03 10:00:00 UTC'));
        $sql = $this->statements();

        self::assertSame(60, $planned, '09:01 to 10:00 were new; 08:01 to 09:00 were planned by the first pass');
        self::assertSame(60, self::statementsStarting($sql, 'INSERT'));
        $rows = $this->orm->getAdapter()->execute('SELECT COUNT(*) AS n FROM scheduler_runs')->rows;
        self::assertSame(120, (int) $rows[0]['n']);
    }

    protected function tearDown(): void
    {
        $this->orm->disableQueryLog();
    }

    /**
     * The SQL run on scheduler_runs since the last call. The adapter's own
     * once-per-connection probes (SELECT sqlite_version()) are not the
     * planner's cost and are left out.
     *
     * @return list<string>
     */
    private function statements(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $q): string => $q['sql'], $this->orm->drainQueryLog()),
            static fn (string $sql): bool => str_contains($sql, 'scheduler_runs'),
        ));
    }

    /** @param list<string> $statements */
    private static function statementsStarting(array $statements, string $verb): int
    {
        return count(array_filter(
            $statements,
            static fn (string $sql): bool => str_starts_with(strtoupper(ltrim($sql)), $verb),
        ));
    }

    private function everyMinute(string $cursor): ScheduleDefinition
    {
        $definition = new ScheduleDefinition();
        $definition->setScheduleKey('minutely');
        // class_exists() is all the planner asks of the job class.
        $definition->setJobClass(\stdClass::class);
        $definition->setCronExpression('* * * * *');
        $definition->setTimezone('UTC');
        $definition->setPool('default');
        $definition->setOverlapPolicy('allow');
        $definition->setMisfirePolicy('catch_up');
        $definition->setTenantMode('global');
        $definition->setMaxCatchUpRuns(null);
        $definition->setMaxAttempts(1);
        $definition->setRetryBackoffSeconds(0);
        $definition->setEnabled(true);
        $definition->setPlanningCursorAt(new \DateTimeImmutable($cursor . ' UTC'));

        return $definition;
    }
}
