<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;

#[FromTable(name: 'scheduler_run_history')]
#[Index(columns: ['run_id', 'created_at'], name: 'idx_scheduler_history_run_created')]
#[Index(columns: ['event_type', 'created_at'], name: 'idx_scheduler_history_event_created')]
class SchedulerRunHistoryResource
{
    use HasUuidV7;
    use HasTimestamps;

    #[Column(type: MySqlType::Binary, length: 16)]
    public string $run_id = '';

    #[Column(type: MySqlType::Varchar, length: 64)]
    public string $event_type = '';

    #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
    public ?string $from_status = null;

    #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
    public ?string $to_status = null;

    #[Column(type: MySqlType::Varchar, length: 128, nullable: true)]
    public ?string $worker_id = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $message = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $context_json = null;
}
