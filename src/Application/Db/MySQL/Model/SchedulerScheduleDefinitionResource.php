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
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;

#[FromTable(name: 'scheduler_schedule_definitions', mapTo: ScheduleDefinition::class)]
#[Index(columns: ['schedule_key'], unique: true, name: 'uniq_scheduler_schedule_key')]
#[Index(columns: ['enabled', 'pool'], name: 'idx_scheduler_definitions_enabled_pool')]
class SchedulerScheduleDefinitionResource implements DomainMappable
{
    use HasUuidV7;
    use HasTimestamps;

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

    public function toDomain(): ScheduleDefinition
    {
        $d = new ScheduleDefinition();
        $d->id = $this->id;
        $d->scheduleKey = $this->schedule_key;
        $d->jobClass = $this->job_class;
        $d->cronExpression = $this->cron_expression;
        $d->timezone = $this->timezone;
        $d->pool = $this->pool;
        $d->overlapPolicy = $this->overlap_policy;
        $d->misfirePolicy = $this->misfire_policy;
        $d->tenantMode = $this->tenant_mode;
        $d->maxCatchUpRuns = $this->max_catch_up_runs;
        $d->maxAttempts = $this->max_attempts;
        $d->retryBackoffSeconds = $this->retry_backoff_seconds;
        $d->enabled = $this->enabled;
        $d->planningCursorAt = $this->planning_cursor_at;
        $d->lastPlannedAt = $this->last_planned_at;
        $d->payloadTemplateJson = $this->payload_template_json;
        $d->createdAt = $this->created_at;
        $d->updatedAt = $this->updated_at;
        return $d;
    }

    public static function fromDomain(object $entity): static
    {
        assert($entity instanceof ScheduleDefinition);
        $r = new static();
        $r->id = $entity->id;
        $r->schedule_key = $entity->scheduleKey;
        $r->job_class = $entity->jobClass;
        $r->cron_expression = $entity->cronExpression;
        $r->timezone = $entity->timezone;
        $r->pool = $entity->pool;
        $r->overlap_policy = $entity->overlapPolicy;
        $r->misfire_policy = $entity->misfirePolicy;
        $r->tenant_mode = $entity->tenantMode;
        $r->max_catch_up_runs = $entity->maxCatchUpRuns;
        $r->max_attempts = $entity->maxAttempts;
        $r->retry_backoff_seconds = $entity->retryBackoffSeconds;
        $r->enabled = $entity->enabled;
        $r->planning_cursor_at = $entity->planningCursorAt;
        $r->last_planned_at = $entity->lastPlannedAt;
        $r->payload_template_json = $entity->payloadTemplateJson;
        $r->created_at = $entity->createdAt;
        $r->updated_at = $entity->updatedAt;
        return $r;
    }
}
