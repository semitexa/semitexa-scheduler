<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Integration\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\ScheduledRunRepository;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Application\Service\RetryScheduler;
use Semitexa\Scheduler\Domain\Exception\LeaseLostException;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Tests\Support\RepositoryHarness;

/**
 * A worker whose lease was reclaimed keeps executing until its next renewal
 * (or to the end, if the job never renews). When it then records the outcome,
 * the run may already belong to another worker: that write must be refused,
 * not laid over the state the new owner is writing.
 */
final class RunOutcomeOwnershipTest extends TestCase
{
    private DatabaseAdapterInterface $db;
    private ScheduledRunRepository $runs;
    private RetryScheduler $retries;

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
        $this->db->execute(
            'CREATE TABLE scheduler_run_history (
                id BLOB PRIMARY KEY,
                run_id BLOB NOT NULL,
                event_type TEXT NOT NULL,
                from_status TEXT,
                to_status TEXT,
                worker_id TEXT,
                message TEXT,
                context_json TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
        );
        $this->runs = RepositoryHarness::repository(ScheduledRunRepository::class, $orm);
        $this->retries = new RetryScheduler(
            $this->runs,
            RepositoryHarness::repository(SchedulerRunHistoryRepository::class, $orm),
        );
    }

    #[Test]
    public function a_worker_that_lost_its_lease_cannot_overwrite_the_new_owners_state(): void
    {
        $run = $this->runRunningAs('w-1', maxAttempts: 1);
        $this->takeOver($run, 'w-2');

        try {
            $this->retries->markFailed($run, 'w-1', 'boom');
            self::fail('Recording an outcome for a lost run must be refused.');
        } catch (LeaseLostException) {
        }

        $row = $this->rowFor($run->getId());
        self::assertSame('running', $row['status'], 'The new owner\'s state must survive.');
        self::assertSame('w-2', $row['lease_owner']);
        self::assertNull($row['last_error']);
        self::assertSame(0, $this->historyCount(), 'A refused outcome must not reach the history.');
    }

    #[Test]
    public function a_worker_that_lost_its_lease_cannot_schedule_a_retry_over_it(): void
    {
        $run = $this->runRunningAs('w-1', maxAttempts: 3);
        $this->takeOver($run, 'w-2');

        $this->expectException(LeaseLostException::class);
        try {
            $this->retries->scheduleRetry($run, 'w-1', 'boom');
        } finally {
            self::assertSame('running', $this->rowFor($run->getId())['status']);
            self::assertSame('w-2', $this->rowFor($run->getId())['lease_owner']);
        }
    }

    #[Test]
    public function a_reclaimed_run_is_not_finalized_by_its_former_owner(): void
    {
        $run = $this->runRunningAs('w-1', maxAttempts: 1);
        $this->db->execute(
            "UPDATE scheduler_runs SET status = 'pending', lease_owner = NULL, lease_expires_at = NULL WHERE id = :id",
            ['id' => Uuid7::toBytes($run->getId())],
        );

        $run->setStatus('succeeded');
        $run->setLeaseOwner(null);

        self::assertFalse($this->runs->finalizeIfOwned($run, 'w-1'));
        self::assertSame('pending', $this->rowFor($run->getId())['status']);
    }

    #[Test]
    public function the_owner_records_its_outcome_and_releases_the_lease(): void
    {
        $run = $this->runRunningAs('w-1', maxAttempts: 1);

        $this->retries->markFailed($run, 'w-1', 'boom');

        $row = $this->rowFor($run->getId());
        self::assertSame('failed', $row['status']);
        self::assertSame('boom', $row['last_error']);
        self::assertNull($row['lease_owner']);
        self::assertNull($row['lease_expires_at']);
        self::assertNotNull($row['finished_at']);
        self::assertSame(1, $this->historyCount());
    }

    #[Test]
    public function an_unleased_inline_run_records_its_outcome(): void
    {
        $run = $this->runRunningAs(null, maxAttempts: 3);

        self::assertTrue($this->retries->scheduleRetry($run, 'run-now-inline-1', 'boom'));
        self::assertSame('retry_scheduled', $this->rowFor($run->getId())['status']);
    }

    /** A run as the worker holds it mid-execution: claimed by $owner, marked running, loaded back. */
    private function runRunningAs(?string $owner, int $maxAttempts): ScheduledRun
    {
        $id = Uuid7::generate();
        $now = new \DateTimeImmutable();
        $this->db->execute(
            "INSERT INTO scheduler_runs
                (id, source_type, job_class, pool, status, available_at, attempt_count, max_attempts,
                 retry_backoff_seconds, lease_owner, lease_expires_at, created_at, updated_at)
             VALUES (:id, 'delayed', 'App\\Job', 'default', 'running', :available_at, 1, :max_attempts,
                 0, :owner, :lease_until, :now_created, :now_updated)",
            [
                'id' => Uuid7::toBytes($id),
                'available_at' => $now->modify('-1 minute')->format('Y-m-d H:i:s.u'),
                'max_attempts' => $maxAttempts,
                'owner' => $owner,
                'lease_until' => $owner !== null ? $now->modify('+5 minutes')->format('Y-m-d H:i:s.u') : null,
                'now_created' => $now->format('Y-m-d H:i:s.u'),
                'now_updated' => $now->format('Y-m-d H:i:s.u'),
            ],
        );

        $run = $this->runs->findById($id);
        self::assertNotNull($run);
        self::assertSame($owner, $run->getLeaseOwner());

        return $run;
    }

    private function takeOver(ScheduledRun $run, string $newOwner): void
    {
        $this->db->execute(
            "UPDATE scheduler_runs SET status = 'running', lease_owner = :owner WHERE id = :id",
            ['owner' => $newOwner, 'id' => Uuid7::toBytes($run->getId())],
        );
    }

    /** @return array<string, mixed> */
    private function rowFor(string $uuid): array
    {
        $rows = $this->db->execute('SELECT * FROM scheduler_runs WHERE id = :id', ['id' => Uuid7::toBytes($uuid)])->rows;
        self::assertNotEmpty($rows, "Run {$uuid} must exist.");

        return $rows[0];
    }

    private function historyCount(): int
    {
        return count($this->db->execute('SELECT id FROM scheduler_run_history')->rows);
    }
}
