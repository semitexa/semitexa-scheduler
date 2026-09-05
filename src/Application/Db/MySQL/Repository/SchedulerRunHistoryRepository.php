<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunHistoryResource;
use Semitexa\Scheduler\Domain\Model\RunHistoryEntry;

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
        // The id is left empty: the ORM mints it on insert. Byte-packing the
        // run id and encoding the context are the mapper's job now.
        $this->repository()->insert(new RunHistoryEntry(
            id: '',
            runId: $runId,
            eventType: $eventType,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
            workerId: $workerId,
            message: $message,
            context: $context,
        ));
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            SchedulerRunHistoryResource::class,
            RunHistoryEntry::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
