<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunHistoryResource;
use Semitexa\Scheduler\Domain\Model\RunHistoryEntry;

/**
 * The bridge between the MySQL row and the history entry.
 *
 * ONE real conversion: the event's detail is a JSON string in the column and an
 * array in the entry. That is a storage shape no column type can express, which
 * is exactly what a mapper is for.
 *
 * The ids are not the second one, however much they look like it. `id` and
 * `run_id` are BINARY(16), and the ORM's TypeCaster converts that column type in
 * both directions on its own. Converting it again here threw «Expected 16 bytes,
 * got 36» on the first history row — and since the write engine maps every
 * persisted row back to its domain model, EVERY scheduled job died on it,
 * whichever job it was (tk-scheduler-history-uuid-roundtrip). The read side was
 * fixed then; the write side stayed, working by accident, until
 * `semitexa.mapperTypeConversion` made the whole shape refusable.
 */
#[AsMapper(resourceModel: SchedulerRunHistoryResource::class, domainModel: RunHistoryEntry::class)]
final class SchedulerRunHistoryMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof SchedulerRunHistoryResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        $context = $resourceModel->context_json === null
            ? null
            : json_decode($resourceModel->context_json, true);

        return new RunHistoryEntry(
            id: $resourceModel->id,
            runId: $resourceModel->run_id,
            eventType: $resourceModel->event_type,
            fromStatus: $resourceModel->from_status,
            toStatus: $resourceModel->to_status,
            workerId: $resourceModel->worker_id,
            message: $resourceModel->message,
            // A hand-edited row must not take the history read down with it.
            context: is_array($context) ? $context : null,
            createdAt: $resourceModel->created_at,
            updatedAt: $resourceModel->updated_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof RunHistoryEntry || throw new \InvalidArgumentException('Unexpected domain model.');

        return new SchedulerRunHistoryResource(
            id: $domainModel->getId(),
            run_id: $domainModel->getRunId(),
            event_type: $domainModel->getEventType(),
            from_status: $domainModel->getFromStatus(),
            to_status: $domainModel->getToStatus(),
            worker_id: $domainModel->getWorkerId(),
            message: $domainModel->getMessage(),
            context_json: $domainModel->getContext() === null
                ? null
                : json_encode($domainModel->getContext(), JSON_THROW_ON_ERROR),
            created_at: $domainModel->getCreatedAt(),
            updated_at: $domainModel->getUpdatedAt(),
        );
    }
}
