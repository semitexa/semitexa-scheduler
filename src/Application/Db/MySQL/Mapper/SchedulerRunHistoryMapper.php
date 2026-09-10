<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunHistoryResource;
use Semitexa\Scheduler\Domain\Model\RunHistoryEntry;

/**
 * The bridge between the MySQL row and the history entry.
 *
 * Two real conversions, not naming: run ids are 16 raw bytes in the column and
 * a UUID string in the entry, and the event's detail is a JSON string in one and
 * an array in the other. Both are this table's storage choices, which is exactly
 * what a mapper is for.
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
            // The hydrator already turns BINARY(16) into the canonical string;
            // converting again threw "Expected 16 bytes, got 36" and killed the
            // scheduler worker on its first history row (tk-scheduler-history-
            // uuid-roundtrip). Raw bytes are still accepted for a caller that
            // hands them over unhydrated.
            id: self::uuid($resourceModel->id),
            runId: self::uuid($resourceModel->run_id),
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
            id: $domainModel->getId() === '' ? '' : Uuid7::toBytes($domainModel->getId()),
            run_id: $domainModel->getRunId() === '' ? '' : Uuid7::toBytes($domainModel->getRunId()),
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

    private static function uuid(string $value): string
    {
        return strlen($value) === 16 ? Uuid7::fromBytes($value) : $value;
    }
}
