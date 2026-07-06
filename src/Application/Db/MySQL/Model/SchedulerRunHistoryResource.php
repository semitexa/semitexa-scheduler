<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * One append-only run-lifecycle event (`scheduler_run_history`).
 *
 * `final readonly` per the ORM contract: rows are built once via the
 * constructor and never mutated; the write engine generates the UUID
 * primary key on insert and RETURNS the persisted instance.
 */
#[FromTable(name: 'scheduler_run_history')]
#[Index(columns: ['run_id', 'created_at'], name: 'idx_scheduler_history_run_created')]
#[Index(columns: ['event_type', 'created_at'], name: 'idx_scheduler_history_event_created')]
final readonly class SchedulerRunHistoryResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id = '',

        #[Column(type: MySqlType::Binary, length: 16)]
        public string $run_id = '',

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $event_type = '',

        #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
        public ?string $from_status = null,

        #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
        public ?string $to_status = null,

        #[Column(type: MySqlType::Varchar, length: 128, nullable: true)]
        public ?string $worker_id = null,

        #[Column(type: MySqlType::LongText, nullable: true)]
        public ?string $message = null,

        #[Column(type: MySqlType::LongText, nullable: true)]
        public ?string $context_json = null,

        #[Column(type: MySqlType::Datetime)]
        public ?\DateTimeImmutable $created_at = null,

        #[Column(type: MySqlType::Datetime)]
        public ?\DateTimeImmutable $updated_at = null,
    ) {
    }

    /**
     * Rebuild the row with the given property overrides (property name =>
     * value). `updated_at` refreshes automatically unless overridden.
     *
     * @param array<string, mixed> $overrides
     */
    public function copyWith(array $overrides): self
    {
        $overrides += ['updated_at' => new \DateTimeImmutable()];

        return new self(...array_merge(get_object_vars($this), $overrides));
    }
}
