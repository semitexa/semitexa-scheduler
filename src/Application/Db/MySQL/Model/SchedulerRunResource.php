<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;

#[FromTable(name: 'scheduler_runs')]
#[Index(columns: ['occurrence_key'], unique: true, name: 'uniq_scheduler_run_occurrence')]
#[Index(columns: ['pool', 'status', 'available_at'], name: 'idx_scheduler_runs_pool_status_available')]
#[Index(columns: ['status', 'lease_expires_at'], name: 'idx_scheduler_runs_status_lease_expiry')]
#[Index(columns: ['tenant_id', 'status', 'available_at'], name: 'idx_scheduler_runs_tenant_status_available')]
#[Index(columns: ['schedule_key', 'scheduled_for'], name: 'idx_scheduler_runs_schedule_scheduled_for')]
#[Index(columns: ['lock_key', 'status'], name: 'idx_scheduler_runs_lock_status')]
class SchedulerRunResource
{
    use HasUuidV7;
    use HasTimestamps;
    use HasColumnReferences;
    use HasRelationReferences;

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
}
