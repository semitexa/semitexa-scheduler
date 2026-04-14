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

#[FromTable(name: 'scheduler_schedule_definitions')]
#[Index(columns: ['schedule_key'], unique: true, name: 'uniq_scheduler_schedule_key')]
#[Index(columns: ['enabled', 'pool'], name: 'idx_scheduler_definitions_enabled_pool')]
class SchedulerScheduleDefinitionResource
{
    use HasUuidV7;
    use HasTimestamps;
    use HasColumnReferences;
    use HasRelationReferences;

    #[Column(type: MySqlType::Varchar, length: 191)]
    public string $schedule_key = '';

    #[Column(type: MySqlType::Varchar, length: 255)]
    public string $job_class = '';

    #[Column(type: MySqlType::Varchar, length: 128)]
    public string $cron_expression = '';

    #[Column(type: MySqlType::Varchar, length: 64)]
    public string $timezone = 'UTC';

    #[Column(type: MySqlType::Varchar, length: 64)]
    public string $pool = 'default';

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $overlap_policy = 'skip';

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $misfire_policy = 'run_once';

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $tenant_mode = 'global';

    #[Column(type: MySqlType::Int, nullable: true)]
    public ?int $max_catch_up_runs = null;

    #[Column(type: MySqlType::Int)]
    public int $max_attempts = 1;

    #[Column(type: MySqlType::Int)]
    public int $retry_backoff_seconds = 0;

    #[Column(type: MySqlType::Boolean)]
    public bool $enabled = true;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $planning_cursor_at = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $last_planned_at = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $payload_template_json = null;
}
