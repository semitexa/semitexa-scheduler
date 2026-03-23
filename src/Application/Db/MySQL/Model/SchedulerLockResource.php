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
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Scheduler\Domain\Model\SchedulerLock;

#[FromTable(name: 'scheduler_locks', mapTo: SchedulerLock::class)]
#[Index(columns: ['lock_key'], unique: true, name: 'uniq_scheduler_lock_key')]
#[Index(columns: ['expires_at'], name: 'idx_scheduler_locks_expires_at')]
class SchedulerLockResource implements DomainMappable
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

    public function toDomain(): SchedulerLock
    {
        $l = new SchedulerLock();
        $l->id = $this->id;
        $l->lockKey = $this->lock_key;
        $l->runId = strlen($this->run_id) === 16 ? Uuid7::fromBytes($this->run_id) : $this->run_id;
        $l->workerId = $this->worker_id;
        $l->acquiredAt = $this->acquired_at;
        $l->expiresAt = $this->expires_at;
        $l->createdAt = $this->created_at;
        $l->updatedAt = $this->updated_at;
        return $l;
    }

    public static function fromDomain(object $entity): static
    {
        assert($entity instanceof SchedulerLock);
        $r = new static();
        $r->id = $entity->id;
        $r->lock_key = $entity->lockKey;
        $r->run_id = strlen($entity->runId) === 36 ? Uuid7::toBytes($entity->runId) : $entity->runId;
        $r->worker_id = $entity->workerId;
        $r->acquired_at = $entity->acquiredAt;
        $r->expires_at = $entity->expiresAt;
        $r->created_at = $entity->createdAt;
        $r->updated_at = $entity->updatedAt;
        return $r;
    }
}
