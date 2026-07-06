<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Integration\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerLockRepository;
use Semitexa\Scheduler\Tests\Support\RepositoryHarness;

/**
 * Overlap-protection lock lifecycle through the real repository SQL on
 * in-memory SQLite: first acquire wins, a live lock cannot be stolen, an
 * expired one can, extend/release honour ownership, sweep removes only
 * expired rows. The unique index on lock_key is part of the contract —
 * acquire() relies on the constraint violation to detect contention.
 */
final class SchedulerLockLifecycleTest extends TestCase
{
    private SchedulerLockRepository $repository;
    private DatabaseAdapterInterface $db;

    protected function setUp(): void
    {
        $orm = RepositoryHarness::sqliteOrm();
        $this->db = $orm->getAdapter();
        $this->db->execute(
            'CREATE TABLE scheduler_locks (
                id BLOB PRIMARY KEY,
                lock_key TEXT NOT NULL UNIQUE,
                run_id BLOB NOT NULL,
                worker_id TEXT NOT NULL,
                acquired_at TEXT,
                expires_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
        );
        $this->repository = RepositoryHarness::repository(SchedulerLockRepository::class, $orm);
    }

    #[Test]
    public function first_acquire_wins_and_live_lock_resists_takeover(): void
    {
        self::assertTrue($this->repository->acquire('job:a', Uuid7::generate(), 'w-1', 60));
        self::assertFalse(
            $this->repository->acquire('job:a', Uuid7::generate(), 'w-2', 60),
            'A live lock must not be stealable.',
        );

        self::assertSame('w-1', $this->lockRow('job:a')['worker_id']);
    }

    #[Test]
    public function expired_lock_is_stolen_by_the_next_acquirer(): void
    {
        $this->seedLock('job:a', worker: 'w-dead', expiresAt: '-1 minute');

        self::assertTrue($this->repository->acquire('job:a', Uuid7::generate(), 'w-new', 60));

        $row = $this->lockRow('job:a');
        self::assertSame('w-new', $row['worker_id']);
        self::assertGreaterThan(
            (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            $row['expires_at'],
            'Steal must re-arm the expiry.',
        );
    }

    #[Test]
    public function extend_honours_ownership(): void
    {
        $this->repository->acquire('job:a', Uuid7::generate(), 'w-1', 60);

        self::assertFalse($this->repository->extend('job:a', 'w-intruder', 3600));
        self::assertTrue($this->repository->extend('job:a', 'w-1', 3600));
    }

    #[Test]
    public function release_honours_ownership_and_frees_the_key(): void
    {
        $this->repository->acquire('job:a', Uuid7::generate(), 'w-1', 60);

        $this->repository->release('job:a', 'w-intruder');
        self::assertNotEmpty($this->db->execute('SELECT 1 FROM scheduler_locks WHERE lock_key = :k', ['k' => 'job:a'])->rows);

        $this->repository->release('job:a', 'w-1');
        self::assertTrue(
            $this->repository->acquire('job:a', Uuid7::generate(), 'w-2', 60),
            'A released key must be immediately acquirable.',
        );
    }

    #[Test]
    public function find_by_key_hydrates_the_domain_lock(): void
    {
        $runId = Uuid7::generate();
        $this->repository->acquire('job:a', $runId, 'w-1', 60);

        $lock = $this->repository->findByKey('job:a');

        self::assertNotNull($lock);
        self::assertSame('job:a', $lock->lockKey);
        self::assertSame('w-1', $lock->workerId);
    }

    #[Test]
    public function sweep_deletes_only_expired_locks(): void
    {
        $this->seedLock('job:dead', worker: 'w-1', expiresAt: '-1 minute');
        $this->repository->acquire('job:live', Uuid7::generate(), 'w-2', 3600);

        $deleted = $this->repository->deleteExpired(new \DateTimeImmutable());

        self::assertSame(1, $deleted);
        self::assertEmpty($this->db->execute('SELECT 1 FROM scheduler_locks WHERE lock_key = :k', ['k' => 'job:dead'])->rows);
        self::assertNotEmpty($this->db->execute('SELECT 1 FROM scheduler_locks WHERE lock_key = :k', ['k' => 'job:live'])->rows);
    }

    private function seedLock(string $lockKey, string $worker, string $expiresAt): void
    {
        $now = new \DateTimeImmutable();
        $this->db->execute(
            'INSERT INTO scheduler_locks (id, lock_key, run_id, worker_id, acquired_at, expires_at, created_at, updated_at)
             VALUES (:id, :lock_key, :run_id, :worker_id, :acquired_at, :expires_at, :created_at, :updated_at)',
            [
                'id' => Uuid7::toBytes(Uuid7::generate()),
                'lock_key' => $lockKey,
                'run_id' => Uuid7::toBytes(Uuid7::generate()),
                'worker_id' => $worker,
                'acquired_at' => $now->format('Y-m-d H:i:s.u'),
                'expires_at' => $now->modify($expiresAt)->format('Y-m-d H:i:s.u'),
                'created_at' => $now->format('Y-m-d H:i:s.u'),
                'updated_at' => $now->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function lockRow(string $lockKey): array
    {
        $result = $this->db->execute(
            'SELECT * FROM scheduler_locks WHERE lock_key = :k',
            ['k' => $lockKey],
        );

        self::assertNotEmpty($result->rows, "Lock {$lockKey} must exist.");

        return $result->rows[0];
    }
}
