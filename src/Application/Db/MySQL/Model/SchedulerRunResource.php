<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Contract\DomainMappable;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;

#[FromTable(name: 'scheduler_runs', mapTo: ScheduledRun::class)]
#[Index(columns: ['occurrence_key'], unique: true, name: 'uniq_scheduler_run_occurrence')]
#[Index(columns: ['pool', 'status', 'available_at'], name: 'idx_scheduler_runs_pool_status_available')]
#[Index(columns: ['status', 'lease_expires_at'], name: 'idx_scheduler_runs_status_lease_expiry')]
#[Index(columns: ['tenant_id', 'status', 'available_at'], name: 'idx_scheduler_runs_tenant_status_available')]
#[Index(columns: ['schedule_key', 'scheduled_for'], name: 'idx_scheduler_runs_schedule_scheduled_for')]
#[Index(columns: ['lock_key', 'status'], name: 'idx_scheduler_runs_lock_status')]
class SchedulerRunResource implements DomainMappable
{
    use HasUuidV7;
    use HasTimestamps;

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $source_type = 'delayed';

    #[Column(type: MySqlType::Binary, length: 16, nullable: true)]
    public ?string $schedule_definition_id = null;

    #[Column(type: MySqlType::Varchar, length: 191, nullable: true)]
    public ?string $schedule_key = null;

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $occurrence_key = null;

    #[Column(type: MySqlType::Varchar, length: 255)]
    public string $job_class = '';

    #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
    public ?string $tenant_id = null;

    #[Column(type: MySqlType::Varchar, length: 64)]
    public string $pool = 'default';

    #[Column(type: MySqlType::Varchar, length: 191, nullable: true)]
    public ?string $lock_key = null;

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $status = 'pending';

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $scheduled_for = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $available_at = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $misfired_at = null;

    #[Column(type: MySqlType::Int)]
    public int $attempt_count = 0;

    #[Column(type: MySqlType::Int)]
    public int $max_attempts = 1;

    #[Column(type: MySqlType::Int)]
    public int $retry_backoff_seconds = 0;

    #[Column(type: MySqlType::Varchar, length: 128, nullable: true)]
    public ?string $lease_owner = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $lease_expires_at = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $locked_at = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $started_at = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $finished_at = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $last_heartbeat_at = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $last_error = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $payload_json = null;

    public function toDomain(): ScheduledRun
    {
        $r = new ScheduledRun();
        $r->id = $this->id;
        $r->sourceType = $this->source_type;
        $r->scheduleDefinitionId = $this->normalizeNullableUuid($this->schedule_definition_id);
        $r->scheduleKey = $this->schedule_key;
        $r->occurrenceKey = $this->occurrence_key;
        $r->jobClass = $this->job_class;
        $r->tenantId = $this->tenant_id;
        $r->pool = $this->pool;
        $r->lockKey = $this->lock_key;
        $r->status = $this->status;
        $r->scheduledFor = $this->scheduled_for;
        $r->availableAt = $this->available_at;
        $r->misfiredAt = $this->misfired_at;
        $r->attemptCount = $this->attempt_count;
        $r->maxAttempts = $this->max_attempts;
        $r->retryBackoffSeconds = $this->retry_backoff_seconds;
        $r->leaseOwner = $this->lease_owner;
        $r->leaseExpiresAt = $this->lease_expires_at;
        $r->lockedAt = $this->locked_at;
        $r->startedAt = $this->started_at;
        $r->finishedAt = $this->finished_at;
        $r->lastHeartbeatAt = $this->last_heartbeat_at;
        $r->lastError = $this->last_error;
        $r->payloadJson = $this->payload_json;
        $r->createdAt = $this->created_at;
        $r->updatedAt = $this->updated_at;
        return $r;
    }

    public static function fromDomain(object $entity): static
    {
        assert($entity instanceof ScheduledRun);
        $r = new static();
        $r->id = $entity->id;
        $r->source_type = $entity->sourceType;
        $r->schedule_definition_id = static::normalizeNullableUuidStatic($entity->scheduleDefinitionId);
        $r->schedule_key = $entity->scheduleKey;
        $r->occurrence_key = $entity->occurrenceKey;
        $r->job_class = $entity->jobClass;
        $r->tenant_id = $entity->tenantId;
        $r->pool = $entity->pool;
        $r->lock_key = $entity->lockKey;
        $r->status = $entity->status;
        $r->scheduled_for = $entity->scheduledFor;
        $r->available_at = $entity->availableAt;
        $r->misfired_at = $entity->misfiredAt;
        $r->attempt_count = $entity->attemptCount;
        $r->max_attempts = $entity->maxAttempts;
        $r->retry_backoff_seconds = $entity->retryBackoffSeconds;
        $r->lease_owner = $entity->leaseOwner;
        $r->lease_expires_at = $entity->leaseExpiresAt;
        $r->locked_at = $entity->lockedAt;
        $r->started_at = $entity->startedAt;
        $r->finished_at = $entity->finishedAt;
        $r->last_heartbeat_at = $entity->lastHeartbeatAt;
        $r->last_error = $entity->lastError;
        $r->payload_json = $entity->payloadJson;
        $r->created_at = $entity->createdAt;
        $r->updated_at = $entity->updatedAt;
        return $r;
    }

    private function normalizeNullableUuid(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        // If already a UUID string (36 chars with dashes), return as-is
        if (strlen($value) === 36) {
            return $value;
        }
        return Uuid7::fromBytes($value);
    }

    private static function normalizeNullableUuidStatic(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        // If already binary (16 bytes), return as-is; otherwise convert to bytes
        if (strlen($value) === 36) {
            return Uuid7::toBytes($value);
        }
        return $value;
    }
}
