<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'scheduler_schedule_definitions')]
final readonly class ScheduleDefinitionTableModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,

        #[Column(name: 'schedule_key', type: MySqlType::Varchar, length: 191)]
        public string $scheduleKey,

        #[Column(name: 'job_class', type: MySqlType::Varchar, length: 255)]
        public string $jobClass,

        #[Column(name: 'cron_expression', type: MySqlType::Varchar, length: 128)]
        public string $cronExpression,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $timezone,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $pool,

        #[Column(name: 'overlap_policy', type: MySqlType::Varchar, length: 32)]
        public string $overlapPolicy,

        #[Column(name: 'misfire_policy', type: MySqlType::Varchar, length: 32)]
        public string $misfirePolicy,

        #[Column(name: 'tenant_mode', type: MySqlType::Varchar, length: 32)]
        public string $tenantMode,

        #[Column(name: 'max_catch_up_runs', type: MySqlType::Int, nullable: true)]
        public ?int $maxCatchUpRuns,

        #[Column(name: 'max_attempts', type: MySqlType::Int)]
        public int $maxAttempts,

        #[Column(name: 'retry_backoff_seconds', type: MySqlType::Int)]
        public int $retryBackoffSeconds,

        #[Column(type: MySqlType::Boolean)]
        public bool $enabled,

        #[Column(name: 'planning_cursor_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $planningCursorAt,

        #[Column(name: 'last_planned_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $lastPlannedAt,

        #[Column(name: 'payload_template_json', type: MySqlType::LongText, nullable: true)]
        public ?string $payloadTemplateJson,

        #[Column(name: 'created_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $createdAt,

        #[Column(name: 'updated_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $updatedAt,
    ) {}
}
