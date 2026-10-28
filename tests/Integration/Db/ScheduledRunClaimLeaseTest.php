<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Integration\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\ScheduledRunRepository;
use Semitexa\Scheduler\Tests\Support\RepositoryHarness;

/**
 * Claim/lease invariants of the scheduler worker loop, exercised through
 * the real repository SQL against an in-memory SQLite database. These are
 * the guarantees that keep two workers from running the same job:
 * claim only due+unleased rows, oldest first; leases renew only for their
 * owner; expired leases are reclaimed, live ones are not.
 */
final class ScheduledRunClaimLeaseTest extends TestCase
{
    private ScheduledRunRepository $repository;
    private DatabaseAdapterInterface $db;

    protected function setUp(): void
    {
        $orm = RepositoryHarness::sqliteOrm();
        $this->db = $orm->getAdapter();
        $this->db->execute(
            'CREATE TABLE scheduler_runs (
                id BLOB PRIMARY KEY,
                pool TEXT NOT NULL,
                status TEXT NOT NULL,
                available_at TEXT,
                lease_owner TEXT,
                lease_expires_at TEXT,
                last_heartbeat_at TEXT,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 1,
                finished_at TEXT,
                last_error TEXT,
                updated_at TEXT
            )',
        );
        $this->repository = RepositoryHarness::repository(ScheduledRunRepository::class, $orm);
    }

    #[Test]
    public function claim_returns_null_when_nothing_is_due(): void
    {
        self::assertNull($this->repository->claimNextDue('default', 'w-1', 60));

        $this->seedRun(status: 'pending', availableAt: '+1 hour');
        self::assertNull(
            $this->repository->claimNextDue('default', 'w-1', 60),
            'A run that is not yet available must not be claimed.',
        );
    }

    #[Test]
    public function claim_takes_the_oldest_due_run_and_leases_it(): void
    {
        $this->seedRun(status: 'pending', availableAt: '-1 minute');
        $older = $this->seedRun(status: 'pending', availableAt: '-10 minutes');

        $claimedId = $this->repository->claimNextDue('default', 'w-1', 60);

        self::assertSame($older, $claimedId, 'Oldest available run wins.');

        $row = $this->rowFor($claimedId);
        self::assertSame('claimed', $row['status']);
        self::assertSame('w-1', $row['lease_owner']);
        self::assertNotNull($row['lease_expires_at']);
    }

    #[Test]
    public function claimed_run_is_invisible_to_the_next_claim(): void
    {
        $this->seedRun(status: 'pending', availableAt: '-1 minute');

        self::assertNotNull($this->repository->claimNextDue('default', 'w-1', 60));
        self::assertNull(
            $this->repository->claimNextDue('default', 'w-2', 60),
            'A freshly leased run must not be claimable by another worker.',
        );
    }

    #[Test]
    public function pending_run_with_a_live_lease_is_not_claimable(): void
    {
        $this->seedRun(status: 'pending', availableAt: '-1 minute', leaseExpiresAt: '+5 minutes');

        self::assertNull($this->repository->claimNextDue('default', 'w-2', 60));
    }

    #[Test]
    public function claim_respects_the_pool_boundary(): void
    {
        $this->seedRun(status: 'pending', availableAt: '-1 minute', pool: 'reports');

        self::assertNull($this->repository->claimNextDue('default', 'w-1', 60));
        self::assertNotNull($this->repository->claimNextDue('reports', 'w-1', 60));
    }

    #[Test]
    public function lease_renews_only_for_its_owner(): void
    {
        $this->seedRun(status: 'pending', availableAt: '-1 minute');
        $id = $this->repository->claimNextDue('default', 'w-1', 60);

        self::assertFalse($this->repository->renewLease($id, 'w-intruder', 60));
        self::assertTrue($this->repository->renewLease($id, 'w-1', 3600));

        $row = $this->rowFor($id);
        self::assertNotNull($row['last_heartbeat_at']);
    }

    #[Test]
    public function expired_leases_are_reclaimed_and_live_ones_survive(): void
    {
        $expired = $this->seedRun(status: 'claimed', availableAt: '-10 minutes', leaseOwner: 'w-dead', leaseExpiresAt: '-1 minute');
        $live = $this->seedRun(status: 'running', availableAt: '-10 minutes', leaseOwner: 'w-alive', leaseExpiresAt: '+5 minutes');

        $reclaimed = $this->repository->reclaimExpiredLeases(new \DateTimeImmutable());

        self::assertSame(1, $reclaimed);

        $expiredRow = $this->rowFor($expired);
        self::assertSame('pending', $expiredRow['status']);
        self::assertNull($expiredRow['lease_owner']);
        self::assertNull($expiredRow['lease_expires_at']);

        $liveRow = $this->rowFor($live);
        self::assertSame('running', $liveRow['status']);
        self::assertSame('w-alive', $liveRow['lease_owner']);
    }

    /**
     * The database's clock judges a lease, not the sweeping worker's: one
     * whose clock ran minutes ahead took over leases other workers held, and
     * the job ran twice.
     */
    #[Test]
    public function a_worker_whose_clock_runs_ahead_reclaims_no_live_lease(): void
    {
        $this->seedRun(status: 'pending', availableAt: '-1 minute');
        $id = $this->repository->claimNextDue('default', 'w-1', 60);
        self::assertNotNull($id);

        self::assertSame(0, $this->repository->reclaimExpiredLeases(new \DateTimeImmutable('+5 minutes')));
        self::assertSame('w-1', $this->rowFor($id)['lease_owner']);
        self::assertNull($this->repository->claimNextDue('default', 'w-2', 60));
    }

    #[Test]
    public function a_run_that_killed_its_worker_on_its_last_attempt_fails_instead_of_coming_back(): void
    {
        // Its attempt was counted when it started; sent back to pending each
        // time, a job that crashes its worker ran forever.
        $crashed = $this->seedRun(status: 'running', availableAt: '-10 minutes', leaseOwner: 'w-dead', leaseExpiresAt: '-1 minute');
        $this->db->execute('UPDATE scheduler_runs SET attempt_count = 1, max_attempts = 1 WHERE id = :id', ['id' => Uuid7::toBytes($crashed)]);
        $retryable = $this->seedRun(status: 'running', availableAt: '-10 minutes', leaseOwner: 'w-dead', leaseExpiresAt: '-1 minute');
        $this->db->execute('UPDATE scheduler_runs SET attempt_count = 1, max_attempts = 3 WHERE id = :id', ['id' => Uuid7::toBytes($retryable)]);

        self::assertSame(2, $this->repository->reclaimExpiredLeases(new \DateTimeImmutable()));

        self::assertSame('failed', $this->rowFor($crashed)['status']);
        self::assertNotNull($this->rowFor($crashed)['last_error']);
        self::assertSame('pending', $this->rowFor($retryable)['status']);
    }

    #[Test]
    public function a_claimed_run_whose_lease_lapsed_is_requeued_even_with_no_attempts_left(): void
    {
        // A claimed run never started, so no attempt of it was spent.
        $claimed = $this->seedRun(status: 'claimed', availableAt: '-10 minutes', leaseOwner: 'w-dead', leaseExpiresAt: '-1 minute');
        $this->db->execute('UPDATE scheduler_runs SET attempt_count = 0, max_attempts = 0 WHERE id = :id', ['id' => Uuid7::toBytes($claimed)]);

        self::assertSame(1, $this->repository->reclaimExpiredLeases(new \DateTimeImmutable()));
        self::assertSame('pending', $this->rowFor($claimed)['status']);
    }

    #[Test]
    public function reclaimed_run_becomes_claimable_again(): void
    {
        $this->seedRun(status: 'claimed', availableAt: '-10 minutes', leaseOwner: 'w-dead', leaseExpiresAt: '-1 minute');

        $this->repository->reclaimExpiredLeases(new \DateTimeImmutable());

        self::assertNotNull($this->repository->claimNextDue('default', 'w-new', 60));
    }

    #[Test]
    public function finished_runs_past_retention_go_with_their_history_and_nothing_else_does(): void
    {
        // Nothing deleted them: a job planned every minute added a run and
        // its history rows each time, forever.
        $this->db->execute('CREATE TABLE scheduler_run_history (id BLOB PRIMARY KEY, run_id BLOB NOT NULL, event_type TEXT)');
        $old = $this->seedRun(status: 'succeeded', availableAt: '-40 days');
        $oldPending = $this->seedRun(status: 'pending', availableAt: '-40 days');
        // Kept: finished a day ago (updated_at is when it finished).
        $recent = $this->seedRun(status: 'failed', availableAt: '-40 days');
        $this->db->execute("UPDATE scheduler_runs SET updated_at = :at WHERE id IN (:a, :b)", [
            'at' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s.u'),
            'a' => Uuid7::toBytes($old),
            'b' => Uuid7::toBytes($oldPending),
        ]);
        foreach ([$old, $old, $recent] as $runId) {
            $this->db->execute('INSERT INTO scheduler_run_history (id, run_id, event_type) VALUES (:id, :run, :e)', [
                'id' => Uuid7::toBytes(Uuid7::generate()), 'run' => Uuid7::toBytes($runId), 'e' => 'succeeded',
            ]);
        }

        $this->db->execute("UPDATE scheduler_runs SET updated_at = :at WHERE id = :r", [
            'at' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s.u'),
            'r' => Uuid7::toBytes($recent),
        ]);
        $cancelled = $this->seedRun(status: 'cancelled', availableAt: '-40 days');
        $this->db->execute("UPDATE scheduler_runs SET updated_at = :at WHERE id = :c", [
            'at' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s.u'),
            'c' => Uuid7::toBytes($cancelled),
        ]);

        // In batches: one per call.
        self::assertSame(1, $this->repository->deleteFinishedBefore(new \DateTimeImmutable('-30 days'), 1));
        self::assertSame(1, $this->repository->deleteFinishedBefore(new \DateTimeImmutable('-30 days'), 1));
        self::assertSame(0, $this->repository->deleteFinishedBefore(new \DateTimeImmutable('-30 days'), 1));

        $left = array_map(static fn (array $r): string => Uuid7::fromBytes($r['id']), $this->db->execute('SELECT id FROM scheduler_runs')->rows);
        sort($left);
        $expected = [$oldPending, $recent];
        sort($expected);
        self::assertSame($expected, $left, 'a pending run is never removed, however old');
        self::assertSame(1, count($this->db->execute('SELECT 1 FROM scheduler_run_history')->rows), 'only the deleted run\'s history went');
    }

    private function seedRun(
        string $status,
        string $availableAt,
        string $pool = 'default',
        ?string $leaseOwner = null,
        ?string $leaseExpiresAt = null,
    ): string {
        $uuid = Uuid7::generate();
        $now = new \DateTimeImmutable();

        $this->db->execute(
            'INSERT INTO scheduler_runs (id, pool, status, available_at, lease_owner, lease_expires_at, updated_at)
             VALUES (:id, :pool, :status, :available_at, :lease_owner, :lease_expires_at, :updated_at)',
            [
                'id' => Uuid7::toBytes($uuid),
                'pool' => $pool,
                'status' => $status,
                'available_at' => $now->modify($availableAt)->format('Y-m-d H:i:s.u'),
                'lease_owner' => $leaseOwner,
                'lease_expires_at' => $leaseExpiresAt !== null
                    ? $now->modify($leaseExpiresAt)->format('Y-m-d H:i:s.u')
                    : null,
                'updated_at' => $now->format('Y-m-d H:i:s.u'),
            ],
        );

        return $uuid;
    }

    /** @return array<string, mixed> */
    private function rowFor(string $uuid): array
    {
        $result = $this->db->execute(
            'SELECT * FROM scheduler_runs WHERE id = :id',
            ['id' => Uuid7::toBytes($uuid)],
        );

        self::assertNotEmpty($result->rows, "Run {$uuid} must exist.");

        return $result->rows[0];
    }
}
