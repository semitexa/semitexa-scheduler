<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'scheduler_locks')]
final readonly class SchedulerLockTableModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,

        #[Column(name: 'lock_key', type: MySqlType::Varchar, length: 191)]
        public string $lockKey,

        #[Column(name: 'run_id', type: MySqlType::Binary, length: 16)]
        public string $runId,

        #[Column(name: 'worker_id', type: MySqlType::Varchar, length: 128)]
        public string $workerId,

        #[Column(name: 'acquired_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $acquiredAt,

        #[Column(name: 'expires_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $expiresAt,

        #[Column(name: 'created_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $createdAt,

        #[Column(name: 'updated_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $updatedAt,
    ) {}
}
