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

    #[Test]
    public function reclaimed_run_becomes_claimable_again(): void
    {
        $this->seedRun(status: 'claimed', availableAt: '-10 minutes', leaseOwner: 'w-dead', leaseExpiresAt: '-1 minute');

        $this->repository->reclaimExpiredLeases(new \DateTimeImmutable());

        self::assertNotNull($this->repository->claimNextDue('default', 'w-new', 60));
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
