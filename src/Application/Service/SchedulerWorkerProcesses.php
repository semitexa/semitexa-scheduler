<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\AsWorkerProcessSource;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\WorkerProcessSourceInterface;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Queue\WorkerProcess;
use Semitexa\Scheduler\Attribute\AsScheduledJob;

/**
 * scheduler:plan once a minute (it turns due #[AsScheduledJob] occurrences into
 * runs) and one scheduler:work per pool, the loop that claims and runs them,
 * delayed jobs such as workflow timeouts and retries included. scheduler:work
 * reads one pool: the default one, SCHEDULER_DEFAULT_POOL, and every pool a
 * recurring job names each get theirs. A pool chosen only when a job is
 * dispatched is named by the package that dispatches it.
 */
#[AsService]
#[AsWorkerProcessSource]
final class SchedulerWorkerProcesses implements WorkerProcessSourceInterface
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    public function workerProcesses(): array
    {
        $pools = ['default' => true];
        $configured = getenv('SCHEDULER_DEFAULT_POOL');
        if (is_string($configured) && $configured !== '') {
            $pools[$configured] = true;
        }
        /** @var list<class-string> $jobs */
        $jobs = $this->classDiscovery->findClassesWithAttribute(AsScheduledJob::class);
        foreach ($jobs as $class) {
            foreach ((new \ReflectionClass($class))->getAttributes(AsScheduledJob::class) as $attribute) {
                $pools[$attribute->newInstance()->pool] = true;
            }
        }
        ksort($pools);

        $processes = [new WorkerProcess(['scheduler:plan'], 60)];
        foreach (array_keys($pools) as $pool) {
            // One odd pool name must not take the other pools' workers with it.
            try {
                $processes[] = new WorkerProcess(['scheduler:work', (string) $pool]);
            } catch (\InvalidArgumentException $e) {
                StaticLoggerBridge::warning('scheduler', "Pool '{$pool}' gets no scheduler:work: " . $e->getMessage(), []);
            }
        }

        return $processes;
    }
}
