<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'scheduler_run_history')]
final readonly class SchedulerRunHistoryTableModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $run_id,
        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $event_type,
        #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
        public ?string $from_status,
        #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
        public ?string $to_status,
        #[Column(type: MySqlType::Varchar, length: 128, nullable: true)]
        public ?string $worker_id,
        #[Column(type: MySqlType::LongText, nullable: true)]
        public ?string $message,
        #[Column(type: MySqlType::LongText, nullable: true)]
        public ?string $context_json,
        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $created_at,
        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $updated_at,
    ) {}
}
