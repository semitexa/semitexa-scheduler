<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;

#[AsMapper(resourceModel: SchedulerRunHistoryTableModel::class, domainModel: SchedulerRunHistoryResource::class)]
final class SchedulerRunHistoryMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof SchedulerRunHistoryTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        $resource = new SchedulerRunHistoryResource();
        foreach (get_object_vars($tableModel) as $property => $value) {
            $resource->{$property} = $value;
        }

        return $resource;
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof SchedulerRunHistoryResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new SchedulerRunHistoryTableModel(...get_object_vars($domainModel));
    }
}
