<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunResource;
use Semitexa\Scheduler\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;

#[SatisfiesRepositoryContract(of: ScheduledRunRepositoryInterface::class)]
class ScheduledRunRepository extends AbstractRepository implements ScheduledRunRepositoryInterface
{
    public function __construct(
        private readonly DatabaseAdapterInterface $db,
        ?\Semitexa\Orm\Hydration\StreamingHydrator $hydrator = null,
    ) {
        parent::__construct($db, $hydrator);
    }

    protected function getResourceClass(): string
    {
        return SchedulerRunResource::class;
    }

    public function findById(string $id): ?ScheduledRun
    {
        $binId = Uuid7::toBytes($id);
        $result = $this->db->execute(
            'SELECT * FROM scheduler_runs WHERE id = :id LIMIT 1',
            ['id' => $binId],
        );
        $row = $result->rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        return $this->hydrate($row);
    }

    public function findByOccurrenceKey(string $occurrenceKey): ?ScheduledRun
    {
        $resource = $this->select()
            ->where('occurrence_key', '=', $occurrenceKey)
            ->fetchOneAsResource();
        return $resource?->toDomain();
    }

    public function save(ScheduledRun $run): void
    {
        $resource = SchedulerRunResource::fromDomain($run);
        parent::save($resource);
        $run->id = $resource->id;
    }

    public function claimNextDue(string $pool, string $workerId, int $leaseTtlSeconds): ?string
    {
        $now        = new \DateTimeImmutable();
        $nowStr     = $now->format('Y-m-d H:i:s.u');
        $leaseUntil = $now->modify("+{$leaseTtlSeconds} seconds")->format('Y-m-d H:i:s.u');

        // Step 1: find a candidate
        $result = $this->db->execute(
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

        // Step 2: atomic claim
        $claimed = $this->db->execute(
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
                'worker'      => $workerId,
                'lease_until' => $leaseUntil,
                'now'         => $nowStr,
                'id'          => $candidateId,
            ],
        );

        if ($claimed->rowCount === 0) {
            return null; // Another worker claimed it first
        }

        return Uuid7::fromBytes($candidateId);
    }

    public function renewLease(string $runId, string $workerId, int $leaseTtlSeconds): bool
    {
        $now        = new \DateTimeImmutable();
        $leaseUntil = $now->modify("+{$leaseTtlSeconds} seconds")->format('Y-m-d H:i:s.u');
        $nowStr     = $now->format('Y-m-d H:i:s.u');
        $binId      = Uuid7::toBytes($runId);

        $result = $this->db->execute(
            "UPDATE scheduler_runs
             SET lease_expires_at = :lease_until,
                 last_heartbeat_at = :now,
                 updated_at = :now
             WHERE id = :id AND lease_owner = :worker",
            [
                'lease_until' => $leaseUntil,
                'now'         => $nowStr,
                'id'          => $binId,
                'worker'      => $workerId,
            ],
        );

        return $result->rowCount > 0;
    }

    public function reclaimExpiredLeases(\DateTimeImmutable $now): int
    {
        $nowStr = $now->format('Y-m-d H:i:s.u');
        $result = $this->db->execute(
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

    private function hydrate(array $row): ScheduledRun
    {
        $run = new ScheduledRun();
        $run->id                  = isset($row['id']) ? Uuid7::fromBytes($row['id']) : '';
        $run->sourceType          = $row['source_type'] ?? 'delayed';
        $run->scheduleDefinitionId = isset($row['schedule_definition_id']) ? Uuid7::fromBytes($row['schedule_definition_id']) : null;
        $run->scheduleKey         = $row['schedule_key'] ?? null;
        $run->occurrenceKey       = $row['occurrence_key'] ?? null;
        $run->jobClass            = $row['job_class'] ?? '';
        $run->tenantId            = $row['tenant_id'] ?? null;
        $run->pool                = $row['pool'] ?? 'default';
        $run->lockKey             = $row['lock_key'] ?? null;
        $run->status              = $row['status'] ?? 'pending';
        $run->scheduledFor        = $this->toDatetime($row['scheduled_for'] ?? null);
        $run->availableAt         = $this->toDatetime($row['available_at'] ?? null);
        $run->misfiredAt          = $this->toDatetime($row['misfired_at'] ?? null);
        $run->attemptCount        = (int) ($row['attempt_count'] ?? 0);
        $run->maxAttempts         = (int) ($row['max_attempts'] ?? 1);
        $run->retryBackoffSeconds = (int) ($row['retry_backoff_seconds'] ?? 0);
        $run->leaseOwner          = $row['lease_owner'] ?? null;
        $run->leaseExpiresAt      = $this->toDatetime($row['lease_expires_at'] ?? null);
        $run->lockedAt            = $this->toDatetime($row['locked_at'] ?? null);
        $run->startedAt           = $this->toDatetime($row['started_at'] ?? null);
        $run->finishedAt          = $this->toDatetime($row['finished_at'] ?? null);
        $run->lastHeartbeatAt     = $this->toDatetime($row['last_heartbeat_at'] ?? null);
        $run->lastError           = $row['last_error'] ?? null;
        $run->payloadJson         = $row['payload_json'] ?? null;
        $run->createdAt           = $this->toDatetime($row['created_at'] ?? null);
        $run->updatedAt           = $this->toDatetime($row['updated_at'] ?? null);
        return $run;
    }

    private function toDatetime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        return new \DateTimeImmutable($value);
    }
}
