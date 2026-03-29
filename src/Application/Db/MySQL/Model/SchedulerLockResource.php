<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;

#[FromTable(name: 'scheduler_locks')]
#[Index(columns: ['lock_key'], unique: true, name: 'uniq_scheduler_lock_key')]
#[Index(columns: ['expires_at'], name: 'idx_scheduler_locks_expires_at')]
class SchedulerLockResource
{
    use HasUuidV7;
    use HasTimestamps;

    #[Column(type: MySqlType::Varchar, length: 191)]
    public string $lock_key = '';

    #[Column(type: MySqlType::Binary, length: 16)]
    public string $run_id = '';

    #[Column(type: MySqlType::Varchar, length: 128)]
    public string $worker_id = '';

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $acquired_at = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $expires_at = null;
}
