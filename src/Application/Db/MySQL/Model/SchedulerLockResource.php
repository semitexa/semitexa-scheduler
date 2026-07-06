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
 * One advisory scheduler lock (`scheduler_locks`).
 *
 * `final readonly` per the ORM contract: mutations rebuild the row via
 * {@see copyWith()}; the write engine generates the UUID primary key on
 * insert and RETURNS the persisted instance (repositories pass it back).
 */
#[FromTable(name: 'scheduler_locks')]
#[Index(columns: ['lock_key'], unique: true, name: 'uniq_scheduler_lock_key')]
#[Index(columns: ['expires_at'], name: 'idx_scheduler_locks_expires_at')]
final readonly class SchedulerLockResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id = '',

        #[Column(type: MySqlType::Varchar, length: 191)]
        public string $lock_key = '',

        #[Column(type: MySqlType::Binary, length: 16)]
        public string $run_id = '',

        #[Column(type: MySqlType::Varchar, length: 128)]
        public string $worker_id = '',

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $acquired_at = null,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $expires_at = null,

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
