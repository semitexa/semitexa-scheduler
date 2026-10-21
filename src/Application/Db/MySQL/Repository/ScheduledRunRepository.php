<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunResource;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRetentionInterface;
use Semitexa\Scheduler\Domain\Enum\RunStatus;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;

#[SatisfiesRepositoryContract(of: ScheduledRunRepositoryInterface::class)]
final class ScheduledRunRepository implements ScheduledRunRepositoryInterface, ScheduledRunRetentionInterface
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
        $persisted = $entity->getId() === ''
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
               AND available_at <= :now_avail
               AND (lease_expires_at IS NULL OR lease_expires_at < :now_lease)
             ORDER BY available_at ASC
             LIMIT 1",
            ['pool' => $pool, 'now_avail' => $nowStr, 'now_lease' => $nowStr],
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
                 updated_at = :now_upd
             WHERE id = :id
               AND status IN ('pending', 'retry_scheduled')
               AND available_at <= :now_avail
               AND (lease_expires_at IS NULL OR lease_expires_at < :now_lease)",
            [
                'worker' => $workerId,
                'lease_until' => $leaseUntil,
                'now_upd' => $nowStr,
                'now_avail' => $nowStr,
                'now_lease' => $nowStr,
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
                 last_heartbeat_at = :now_beat,
                 updated_at = :now_upd
             WHERE id = :id AND lease_owner = :worker",
            [
                'lease_until' => $leaseUntil,
                'now_beat' => $nowStr,
                'now_upd' => $nowStr,
                'id' => $binId,
                'worker' => $workerId,
            ],
        );

        if ($result->rowCount > 0) {
            return true;
        }

        // MySQL reports CHANGED rows, and the DATETIME columns keep whole
        // seconds: a second renewal within the same second changes nothing and
        // reports 0 even though this worker still owns the lease. Only a
        // missing ownership row means the lease was lost.
        return $this->adapter()->execute(
            'SELECT 1 FROM scheduler_runs WHERE id = :id AND lease_owner = :worker',
            ['id' => $binId, 'worker' => $workerId],
        )->rows !== [];
    }

    public function finalizeIfOwned(ScheduledRun $run, ?string $expectedOwner): bool
    {
        $binId = Uuid7::toBytes($run->getId());
        [$ownerGuard, $guardParams] = $expectedOwner === null
            ? ['lease_owner IS NULL', []]
            : ['lease_owner = :owner', ['owner' => $expectedOwner]];

        $result = $this->adapter()->execute(
            "UPDATE scheduler_runs
             SET status = :status,
                 available_at = :available_at,
                 last_error = :last_error,
                 finished_at = :finished_at,
                 lease_owner = :new_owner,
                 lease_expires_at = :lease_until,
                 updated_at = :now_upd
             WHERE id = :id AND {$ownerGuard}",
            [
                'status' => $run->getStatus(),
                'available_at' => $run->getAvailableAt()?->format('Y-m-d H:i:s.u'),
                'last_error' => $run->getLastError(),
                'finished_at' => $run->getFinishedAt()?->format('Y-m-d H:i:s.u'),
                'new_owner' => $run->getLeaseOwner(),
                'lease_until' => $run->getLeaseExpiresAt()?->format('Y-m-d H:i:s.u'),
                'now_upd' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                'id' => $binId,
            ] + $guardParams,
        );

        if ($result->rowCount > 0) {
            return true;
        }

        // Same MySQL caveat as renewLease(): 0 CHANGED rows also covers a
        // matched row that already held these values. Only a row that fails
        // the ownership guard means the run now belongs to someone else.
        return $this->adapter()->execute(
            "SELECT 1 FROM scheduler_runs WHERE id = :id AND {$ownerGuard}",
            ['id' => $binId] + $guardParams,
        )->rows !== [];
    }

    public function reclaimExpiredLeases(\DateTimeImmutable $now): int
    {
        $nowStr = $now->format('Y-m-d H:i:s.u');
        // A run whose lease lapsed while running has already had that attempt
        // counted. Out of attempts, it fails here: sent back to pending every
        // time, a job that kills its worker (a fatal error, out of memory) ran
        // forever. A claimed run has not started, so it is only requeued.
        $failed = $this->adapter()->execute(
            "UPDATE scheduler_runs
             SET status = 'failed',
                 lease_owner = NULL,
                 lease_expires_at = NULL,
                 finished_at = :now_fin,
                 last_error = 'Its lease ran out before it finished (the worker stopped, or the job ran past the lease without renewLease()), and its attempts are used up.',
                 updated_at = :now_upd
             WHERE status = 'running'
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at < :now_lease
               AND attempt_count >= max_attempts",
            ['now_fin' => $nowStr, 'now_upd' => $nowStr, 'now_lease' => $nowStr],
        );

        $result = $this->adapter()->execute(
            "UPDATE scheduler_runs
             SET status = 'pending',
                 lease_owner = NULL,
                 lease_expires_at = NULL,
                 updated_at = :now_upd
             WHERE status IN ('claimed', 'running')
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at < :now_lease
               AND (status = 'claimed' OR attempt_count < max_attempts)",
            ['now_upd' => $nowStr, 'now_lease' => $nowStr],
        );

        return $failed->rowCount + $result->rowCount;
    }

    public function deleteFinishedBefore(\DateTimeImmutable $cutoff, int $limit): int
    {
        $finished = implode(', ', array_map(
            static fn (RunStatus $s): string => "'{$s->value}'",
            array_filter(RunStatus::cases(), static fn (RunStatus $s): bool => $s->isTerminal()),
        ));
        // No ORDER BY: with no index on updated_at it sorted the whole table
        // every batch; unordered, the scan stops at the limit.
        $ids = array_column($this->adapter()->execute(
            "SELECT id FROM scheduler_runs
             WHERE status IN ({$finished}) AND updated_at < :cutoff
             LIMIT " . max(1, min($limit, 500)),
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
        )->rows, 'id');
        if ($ids === []) {
            return 0;
        }

        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $params["id{$i}"] = $id;
        }
        $in = ':' . implode(', :', array_keys($params));

        $this->adapter()->execute("DELETE FROM scheduler_run_history WHERE run_id IN ({$in})", $params);

        // The status again: a run cannot change once finished, but the guard
        // costs nothing and keeps a live row out of reach.
        return $this->adapter()->execute(
            "DELETE FROM scheduler_runs WHERE id IN ({$in}) AND status IN ({$finished})",
            $params,
        )->rowCount;
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

    /**
     * The persisted state — the id the insert made, above all — back into the
     * caller's instance. Through reflection: the model's properties are
     * private, and get_object_vars() from here saw none of them, so save()
     * copied nothing and dispatchAt() returned an empty run id.
     */
    private function copyIntoMutableDomain(object $source, ScheduledRun $target): void
    {
        $source instanceof ScheduledRun || throw new \InvalidArgumentException('Unexpected persisted domain model.');

        foreach ((new \ReflectionObject($source))->getProperties() as $property) {
            if (!$property->isStatic() && $property->isInitialized($source)) {
                $property->setValue($target, $property->getValue($source));
            }
        }
    }
}
