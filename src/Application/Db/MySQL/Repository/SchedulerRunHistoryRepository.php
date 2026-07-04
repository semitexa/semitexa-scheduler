<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunHistoryResource;

final class SchedulerRunHistoryRepository
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    public function append(
        string $runId,
        string $eventType,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $workerId = null,
        ?string $message = null,
        ?array $context = null,
    ): void {
        $resource = new SchedulerRunHistoryResource(
            run_id: \Semitexa\Orm\Application\Service\Uuid7::toBytes($runId),
            event_type: $eventType,
            from_status: $fromStatus,
            to_status: $toStatus,
            worker_id: $workerId,
            message: $message,
            context_json: $context !== null ? json_encode($context, JSON_THROW_ON_ERROR) : null,
        );

        $this->repository()->insert($resource);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            SchedulerRunHistoryResource::class,
            SchedulerRunHistoryResource::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
