<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerLockResource;
use Semitexa\Scheduler\Contract\SchedulerLockRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\SchedulerLock;

#[SatisfiesRepositoryContract(of: SchedulerLockRepositoryInterface::class)]
class SchedulerLockRepository extends AbstractRepository implements SchedulerLockRepositoryInterface
{
    public function __construct(
        private readonly DatabaseAdapterInterface $db,
        ?\Semitexa\Orm\Hydration\StreamingHydrator $hydrator = null,
    ) {
        parent::__construct($db, $hydrator);
    }

    protected function getResourceClass(): string
    {
        return SchedulerLockResource::class;
    }

    public function acquire(string $lockKey, string $runId, string $workerId, int $ttlSeconds): bool
    {
        $now      = new \DateTimeImmutable();
        $nowStr   = $now->format('Y-m-d H:i:s.u');
        $expires  = $now->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s.u');
        $newId    = Uuid7::toBytes(Uuid7::generate());
        $binRunId = Uuid7::toBytes($runId);

        // Try to INSERT a fresh lock row (unique key on lock_key)
        try {
            $result = $this->db->execute(
                "INSERT INTO scheduler_locks (id, lock_key, run_id, worker_id, acquired_at, expires_at, created_at, updated_at)
                 VALUES (:id, :lock_key, :run_id, :worker_id, :now, :expires, :now, :now)",
                [
                    'id'        => $newId,
                    'lock_key'  => $lockKey,
                    'run_id'    => $binRunId,
                    'worker_id' => $workerId,
                    'now'       => $nowStr,
                    'expires'   => $expires,
                ],
            );
            return $result->rowCount > 0;
        } catch (\Throwable) {
            // Unique constraint violation — try to replace an expired lock
        }

        // Replace expired lock
        $replaced = $this->db->execute(
            "UPDATE scheduler_locks
             SET run_id = :run_id, worker_id = :worker_id, acquired_at = :now, expires_at = :expires, updated_at = :now
             WHERE lock_key = :lock_key AND expires_at < :now",
            [
                'run_id'    => $binRunId,
                'worker_id' => $workerId,
                'now'       => $nowStr,
                'expires'   => $expires,
                'lock_key'  => $lockKey,
            ],
        );
        return $replaced->rowCount > 0;
    }

    public function extend(string $lockKey, string $workerId, int $ttlSeconds): bool
    {
        $now     = new \DateTimeImmutable();
        $expires = $now->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s.u');
        $nowStr  = $now->format('Y-m-d H:i:s.u');

        $result = $this->db->execute(
            "UPDATE scheduler_locks SET expires_at = :expires, updated_at = :now
             WHERE lock_key = :lock_key AND worker_id = :worker_id",
            ['expires' => $expires, 'now' => $nowStr, 'lock_key' => $lockKey, 'worker_id' => $workerId],
        );
        return $result->rowCount > 0;
    }

    public function release(string $lockKey, string $workerId): void
    {
        $this->db->execute(
            "DELETE FROM scheduler_locks WHERE lock_key = :lock_key AND worker_id = :worker_id",
            ['lock_key' => $lockKey, 'worker_id' => $workerId],
        );
    }

    public function findByKey(string $lockKey): ?SchedulerLock
    {
        $result = $this->db->execute(
            "SELECT * FROM scheduler_locks WHERE lock_key = :key LIMIT 1",
            ['key' => $lockKey],
        );
        $row = $result->rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        $lock = new SchedulerLock();
        $lock->id         = isset($row['id']) ? Uuid7::fromBytes($row['id']) : '';
        $lock->lockKey    = $row['lock_key'];
        $lock->runId      = isset($row['run_id']) ? Uuid7::fromBytes($row['run_id']) : '';
        $lock->workerId   = $row['worker_id'];
        $lock->acquiredAt = $row['acquired_at'] ? new \DateTimeImmutable($row['acquired_at']) : null;
        $lock->expiresAt  = $row['expires_at']  ? new \DateTimeImmutable($row['expires_at'])  : null;
        $lock->createdAt  = $row['created_at']  ? new \DateTimeImmutable($row['created_at'])  : null;
        $lock->updatedAt  = $row['updated_at']  ? new \DateTimeImmutable($row['updated_at'])  : null;
        return $lock;
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        $result = $this->db->execute(
            "DELETE FROM scheduler_locks WHERE expires_at < :now",
            ['now' => $now->format('Y-m-d H:i:s.u')],
        );
        return $result->rowCount;
    }
}
