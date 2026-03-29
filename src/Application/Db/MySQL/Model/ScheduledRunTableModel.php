<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'scheduler_runs')]
final readonly class ScheduledRunTableModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,

        #[Column(name: 'source_type', type: MySqlType::Varchar, length: 32)]
        public string $sourceType,

        #[Column(name: 'schedule_definition_id', type: MySqlType::Binary, length: 16, nullable: true)]
        public ?string $scheduleDefinitionId,

        #[Column(name: 'schedule_key', type: MySqlType::Varchar, length: 191, nullable: true)]
        public ?string $scheduleKey,

        #[Column(name: 'occurrence_key', type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $occurrenceKey,

        #[Column(name: 'job_class', type: MySqlType::Varchar, length: 255)]
        public string $jobClass,

        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenantId,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $pool,

        #[Column(name: 'lock_key', type: MySqlType::Varchar, length: 191, nullable: true)]
        public ?string $lockKey,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status,

        #[Column(name: 'scheduled_for', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $scheduledFor,

        #[Column(name: 'available_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $availableAt,

        #[Column(name: 'misfired_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $misfiredAt,

        #[Column(name: 'attempt_count', type: MySqlType::Int)]
        public int $attemptCount,

        #[Column(name: 'max_attempts', type: MySqlType::Int)]
        public int $maxAttempts,

        #[Column(name: 'retry_backoff_seconds', type: MySqlType::Int)]
        public int $retryBackoffSeconds,

        #[Column(name: 'lease_owner', type: MySqlType::Varchar, length: 128, nullable: true)]
        public ?string $leaseOwner,

        #[Column(name: 'lease_expires_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $leaseExpiresAt,

        #[Column(name: 'locked_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $lockedAt,

        #[Column(name: 'started_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $startedAt,

        #[Column(name: 'finished_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $finishedAt,

        #[Column(name: 'last_heartbeat_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $lastHeartbeatAt,

        #[Column(name: 'last_error', type: MySqlType::LongText, nullable: true)]
        public ?string $lastError,

        #[Column(name: 'payload_json', type: MySqlType::LongText, nullable: true)]
        public ?string $payloadJson,

        #[Column(name: 'created_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $createdAt,

        #[Column(name: 'updated_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $updatedAt,
    ) {}
}
