<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\AsWorkerProcessSource;
use Semitexa\Core\Contract\WorkerProcessSourceInterface;
use Semitexa\Core\Queue\WorkerProcess;

/**
 * scheduler:plan once a minute (it turns due #[AsScheduledJob] occurrences into
 * runs, idempotently) and scheduler:work, the loop that claims and runs them,
 * delayed jobs such as workflow timeouts and retries included. Both are safe
 * in several worker containers at once: occurrences and runs are claimed.
 */
#[AsService]
#[AsWorkerProcessSource]
final class SchedulerWorkerProcesses implements WorkerProcessSourceInterface
{
    public function workerProcesses(): array
    {
        return [
            new WorkerProcess(['scheduler:plan'], 60),
            new WorkerProcess(['scheduler:work']),
        ];
    }
}
