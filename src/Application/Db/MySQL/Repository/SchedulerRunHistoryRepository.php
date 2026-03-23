<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunHistoryResource;

class SchedulerRunHistoryRepository extends AbstractRepository
{
    public function __construct(
        private readonly DatabaseAdapterInterface $db,
        ?\Semitexa\Orm\Hydration\StreamingHydrator $hydrator = null,
    ) {
        parent::__construct($db, $hydrator);
    }

    protected function getResourceClass(): string
    {
        return SchedulerRunHistoryResource::class;
    }

    public function append(
        string $runId,
        string $eventType,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $workerId = null,
        ?string $message = null,
        ?array $context = null,
    ): void {
        $h = new SchedulerRunHistoryResource();
        $h->run_id       = Uuid7::toBytes($runId);
        $h->event_type   = $eventType;
        $h->from_status  = $fromStatus;
        $h->to_status    = $toStatus;
        $h->worker_id    = $workerId;
        $h->message      = $message;
        $h->context_json = $context !== null ? json_encode($context, JSON_THROW_ON_ERROR) : null;
        parent::save($h);
    }
}
