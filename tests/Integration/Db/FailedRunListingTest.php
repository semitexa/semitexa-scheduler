<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Integration\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\ScheduledRunRepository;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Tests\Support\RepositoryHarness;

/**
 * orm-gap-7-package-read-apis: a consumer alerting on failed jobs read
 * scheduler_runs with raw SQL, because the run repository could claim and lease
 * runs but not list them. failedSince() is that read, through the package.
 */
final class FailedRunListingTest extends TestCase
{
    private DatabaseAdapterInterface $db;
    private ScheduledRunRepository $runs;
    /** @var array<string, string> name => run id */
    private array $ids = [];

    protected function setUp(): void
    {
        $orm = RepositoryHarness::sqliteOrm();
        $this->db = $orm->getAdapter();
        $this->db->execute(
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
                attempt_count INTEGER NOT NULL,
                max_attempts INTEGER NOT NULL,
                retry_backoff_seconds INTEGER NOT NULL,
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
        $this->runs = RepositoryHarness::repository(ScheduledRunRepository::class, $orm);

        $now = new \DateTimeImmutable();
        $this->insert('failed_10m', 'failed', $now->modify('-10 minutes'), 'boom 10');
        $this->insert('failed_1m', 'failed', $now->modify('-1 minute'), 'boom 1');
        $this->insert('failed_2d', 'failed', $now->modify('-2 days'), 'old boom');
        $this->insert('succeeded_1m', 'succeeded', $now->modify('-1 minute'), null);
        $this->insert('retrying_1m', 'retry_scheduled', $now->modify('-1 minute'), 'will retry');
    }

    #[Test]
    public function the_runs_that_failed_for_good_since_a_moment_come_latest_first(): void
    {
        $runs = $this->runs->failedSince(new \DateTimeImmutable('-1 hour'), 10);

        self::assertSame([$this->ids['failed_1m'], $this->ids['failed_10m']], $this->idsOf($runs));
        self::assertSame(['boom 1', 'boom 10'], array_map(static fn (ScheduledRun $r): ?string => $r->getLastError(), $runs));
        self::assertSame(['nightly-failed_1m', 'nightly-failed_10m'], array_map(static fn (ScheduledRun $r): ?string => $r->getScheduleKey(), $runs));
        self::assertSame(['App\\Job\\Nightly', 'App\\Job\\Nightly'], array_map(static fn (ScheduledRun $r): string => $r->getJobClass(), $runs));
    }

    #[Test]
    public function the_limit_keeps_the_latest(): void
    {
        self::assertSame([$this->ids['failed_1m']], $this->idsOf($this->runs->failedSince(new \DateTimeImmutable('-1 hour'), 1)));
    }

    #[Test]
    public function a_limit_out_of_range_is_held_to_one_and_five_hundred(): void
    {
        self::assertSame([$this->ids['failed_1m']], $this->idsOf($this->runs->failedSince(new \DateTimeImmutable('-1 hour'), 0)));
        self::assertSame(
            [$this->ids['failed_1m'], $this->ids['failed_10m'], $this->ids['failed_2d']],
            $this->idsOf($this->runs->failedSince(new \DateTimeImmutable('-1 week'), 100_000)),
        );
    }

    #[Test]
    public function nothing_failed_since_is_an_empty_list(): void
    {
        self::assertSame([], $this->runs->failedSince(new \DateTimeImmutable('+1 minute'), 10));
    }

    /**
     * @param list<ScheduledRun> $runs
     * @return list<string>
     */
    private function idsOf(array $runs): array
    {
        return array_map(static fn (ScheduledRun $r): string => $r->getId(), $runs);
    }

    private function insert(string $name, string $status, \DateTimeImmutable $finishedAt, ?string $error): void
    {
        $id = Uuid7::generate();
        $this->ids[$name] = $id;
        $this->db->execute(
            "INSERT INTO scheduler_runs
                (id, source_type, schedule_key, job_class, pool, status, attempt_count, max_attempts,
                 retry_backoff_seconds, finished_at, last_error, created_at, updated_at)
             VALUES (:id, 'schedule', :key, 'App\\Job\\Nightly', 'default', :status, 1, 1, 0, :finished, :error, :created, :updated)",
            [
                'id' => Uuid7::toBytes($id),
                'key' => 'nightly-' . $name,
                'status' => $status,
                'finished' => $finishedAt->format('Y-m-d H:i:s'),
                'error' => $error,
                'created' => $finishedAt->format('Y-m-d H:i:s'),
                'updated' => $finishedAt->format('Y-m-d H:i:s'),
            ],
        );
    }
}
