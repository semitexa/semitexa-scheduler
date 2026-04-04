<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunHistoryResource;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunHistoryTableModel;

final class SchedulerRunHistoryRepository
{
    #[InjectAsReadonly]
    protected ?OrmManager $orm = null;

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
        $resource = new SchedulerRunHistoryResource();
        $resource->run_id = \Semitexa\Orm\Uuid\Uuid7::toBytes($runId);
        $resource->event_type = $eventType;
        $resource->from_status = $fromStatus;
        $resource->to_status = $toStatus;
        $resource->worker_id = $workerId;
        $resource->message = $message;
        $resource->context_json = $context !== null ? json_encode($context, JSON_THROW_ON_ERROR) : null;

        $persisted = $this->repository()->insert($resource);
        $this->copyIntoMutableResource($persisted, $resource);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            SchedulerRunHistoryTableModel::class,
            SchedulerRunHistoryResource::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

    private function copyIntoMutableResource(object $source, SchedulerRunHistoryResource $target): void
    {
        $source instanceof SchedulerRunHistoryResource || throw new \InvalidArgumentException('Unexpected persisted resource.');

        foreach (get_object_vars($source) as $property => $value) {
            $target->{$property} = $value;
        }
    }
}
