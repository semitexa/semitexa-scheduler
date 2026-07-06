<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerLockResource;
use Semitexa\Scheduler\Domain\Contract\SchedulerLockRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\SchedulerLock;

#[SatisfiesRepositoryContract(of: SchedulerLockRepositoryInterface::class)]
final class SchedulerLockRepository implements SchedulerLockRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    public function acquire(string $lockKey, string $runId, string $workerId, int $ttlSeconds): bool
    {
        $now = new \DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $expires = $now->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s.u');
        $newId = Uuid7::toBytes(Uuid7::generate());
        $binRunId = Uuid7::toBytes($runId);

        try {
            $result = $this->adapter()->execute(
                "INSERT INTO scheduler_locks (id, lock_key, run_id, worker_id, acquired_at, expires_at, created_at, updated_at)
                 VALUES (:id, :lock_key, :run_id, :worker_id, :acquired, :expires, :created, :updated)",
                [
                    'id' => $newId,
                    'lock_key' => $lockKey,
                    'run_id' => $binRunId,
                    'worker_id' => $workerId,
                    'acquired' => $nowStr,
                    'expires' => $expires,
                    'created' => $nowStr,
                    'updated' => $nowStr,
                ],
            );

            return $result->rowCount > 0;
        } catch (\Throwable $e) {
            // A duplicate-key violation means the lock row already exists — the
            // lock is held. That is expected contention: fall through to steal
            // it IF it has expired. Any OTHER failure (deadlock, timeout, lost
            // connection) has an unknown outcome; silently proceeding to the
            // steal UPDATE would weaken the distributed lock and can double-run
            // a job. Surface it instead of misreading it as contention.
            if (!self::isDuplicateKeyException($e)) {
                throw $e;
            }
        }

        $replaced = $this->adapter()->execute(
            "UPDATE scheduler_locks
             SET run_id = :run_id, worker_id = :worker_id, acquired_at = :acquired, expires_at = :expires, updated_at = :updated
             WHERE lock_key = :lock_key AND expires_at < :now_guard",
            [
                'run_id' => $binRunId,
                'worker_id' => $workerId,
                'acquired' => $nowStr,
                'expires' => $expires,
                'updated' => $nowStr,
                'lock_key' => $lockKey,
                'now_guard' => $nowStr,
            ],
        );

        return $replaced->rowCount > 0;
    }

    public function extend(string $lockKey, string $workerId, int $ttlSeconds): bool
    {
        $now = new \DateTimeImmutable();
        $expires = $now->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s.u');
        $nowStr = $now->format('Y-m-d H:i:s.u');

        $result = $this->adapter()->execute(
            "UPDATE scheduler_locks SET expires_at = :expires, updated_at = :now
             WHERE lock_key = :lock_key AND worker_id = :worker_id",
            ['expires' => $expires, 'now' => $nowStr, 'lock_key' => $lockKey, 'worker_id' => $workerId],
        );

        return $result->rowCount > 0;
    }

    public function release(string $lockKey, string $workerId): void
    {
        $this->adapter()->execute(
            "DELETE FROM scheduler_locks WHERE lock_key = :lock_key AND worker_id = :worker_id",
            ['lock_key' => $lockKey, 'worker_id' => $workerId],
        );
    }

    public function findByKey(string $lockKey): ?SchedulerLock
    {
        /** @var SchedulerLock|null */
        return $this->repository()->query()
            ->where(SchedulerLockResource::column('lock_key'), Operator::Equals, $lockKey)
            ->fetchOneAs(SchedulerLock::class, $this->orm()->getMapperRegistry());
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        $result = $this->adapter()->execute(
            "DELETE FROM scheduler_locks WHERE expires_at < :now",
            ['now' => $now->format('Y-m-d H:i:s.u')],
        );

        return $result->rowCount;
    }

    /**
     * A unique/primary-key collision, portable across MySQL and SQLite: both
     * surface an integrity-constraint violation as PDO SQLSTATE 23000. The
     * message fallback catches drivers that carry the detail there instead.
     */
    private static function isDuplicateKeyException(\Throwable $e): bool
    {
        if ($e instanceof \PDOException && (string) $e->getCode() === '23000') {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'duplicate');
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            SchedulerLockResource::class,
            SchedulerLock::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

    private function adapter(): \Semitexa\Orm\Adapter\DatabaseAdapterInterface
    {
        return $this->orm()->getAdapter();
    }
}
