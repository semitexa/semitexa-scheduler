<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunResource;
use Semitexa\Scheduler\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;

#[SatisfiesRepositoryContract(of: ScheduledRunRepositoryInterface::class)]
final class ScheduledRunRepository implements ScheduledRunRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    public function findById(string $id): ?ScheduledRun
    {
        /** @var ScheduledRun|null */
        return $this->repository()->findById($id);
    }

    public function findByOccurrenceKey(string $occurrenceKey): ?ScheduledRun
    {
        /** @var ScheduledRun|null */
        return $this->repository()->query()
            ->where(SchedulerRunResource::column('occurrence_key'), Operator::Equals, $occurrenceKey)
            ->fetchOneAs(ScheduledRun::class, $this->orm()->getMapperRegistry());
    }

    public function save(ScheduledRun $entity): void
    {
        $persisted = $entity->id === ''
            ? $this->repository()->insert($entity)
            : $this->repository()->update($entity);

        $this->copyIntoMutableDomain($persisted, $entity);
    }

    public function claimNextDue(string $pool, string $workerId, int $leaseTtlSeconds): ?string
    {
        $now = new \DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $leaseUntil = $now->modify("+{$leaseTtlSeconds} seconds")->format('Y-m-d H:i:s.u');

        $result = $this->adapter()->execute(
            "SELECT id FROM scheduler_runs
             WHERE pool = :pool
               AND status IN ('pending', 'retry_scheduled')
               AND available_at <= :now
               AND (lease_expires_at IS NULL OR lease_expires_at < :now)
             ORDER BY available_at ASC
             LIMIT 1",
            ['pool' => $pool, 'now' => $nowStr],
        );

        $row = $result->rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        $candidateId = $row['id'];

        $claimed = $this->adapter()->execute(
            "UPDATE scheduler_runs
             SET status = 'claimed',
                 lease_owner = :worker,
                 lease_expires_at = :lease_until,
                 updated_at = :now
             WHERE id = :id
               AND status IN ('pending', 'retry_scheduled')
               AND available_at <= :now
               AND (lease_expires_at IS NULL OR lease_expires_at < :now)",
            [
                'worker' => $workerId,
                'lease_until' => $leaseUntil,
                'now' => $nowStr,
                'id' => $candidateId,
            ],
        );

        if ($claimed->rowCount === 0) {
            return null;
        }

        return Uuid7::fromBytes($candidateId);
    }

    public function renewLease(string $runId, string $workerId, int $leaseTtlSeconds): bool
    {
        $now = new \DateTimeImmutable();
        $leaseUntil = $now->modify("+{$leaseTtlSeconds} seconds")->format('Y-m-d H:i:s.u');
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $binId = Uuid7::toBytes($runId);

        $result = $this->adapter()->execute(
            "UPDATE scheduler_runs
             SET lease_expires_at = :lease_until,
                 last_heartbeat_at = :now,
                 updated_at = :now
             WHERE id = :id AND lease_owner = :worker",
            [
                'lease_until' => $leaseUntil,
                'now' => $nowStr,
                'id' => $binId,
                'worker' => $workerId,
            ],
        );

        return $result->rowCount > 0;
    }

    public function reclaimExpiredLeases(\DateTimeImmutable $now): int
    {
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $result = $this->adapter()->execute(
            "UPDATE scheduler_runs
             SET status = 'pending',
                 lease_owner = NULL,
                 lease_expires_at = NULL,
                 updated_at = :now
             WHERE status IN ('claimed', 'running')
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at < :now",
            ['now' => $nowStr],
        );

        return $result->rowCount;
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            SchedulerRunResource::class,
            ScheduledRun::class,
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

    private function copyIntoMutableDomain(object $source, ScheduledRun $target): void
    {
        $source instanceof ScheduledRun || throw new \InvalidArgumentException('Unexpected persisted domain model.');

        foreach (get_object_vars($source) as $property => $value) {
            $target->{$property} = $value;
        }
    }
}
