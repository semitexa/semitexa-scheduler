<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Queue\WorkerProcess;
use Semitexa\Scheduler\Application\Service\SchedulerWorkerProcesses;
use Semitexa\Scheduler\Attribute\AsScheduledJob;
use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;

/**
 * What the worker container runs for the scheduler (tk-worker-runs-mail-work).
 * scheduler:work reads one pool: a job on any other pool was never run (review).
 */
final class SchedulerWorkerProcessesTest extends TestCase
{
    private string|false $defaultPool = false;

    protected function setUp(): void
    {
        $this->defaultPool = getenv('SCHEDULER_DEFAULT_POOL');
        putenv('SCHEDULER_DEFAULT_POOL');
    }

    protected function tearDown(): void
    {
        putenv($this->defaultPool === false ? 'SCHEDULER_DEFAULT_POOL' : 'SCHEDULER_DEFAULT_POOL=' . $this->defaultPool);
    }

    #[Test]
    public function the_planner_once_a_minute_and_a_worker_for_every_pool_a_job_names(): void
    {
        putenv('SCHEDULER_DEFAULT_POOL=bulk');

        self::assertSame(
            ['60 scheduler:plan', '5 scheduler:work bulk', '5 scheduler:work default', '5 scheduler:work reports'],
            $this->lines([PoolsFixtureReportJob::class, PoolsFixtureDefaultJob::class]),
        );
    }

    #[Test]
    public function with_no_job_the_default_pool_still_gets_its_worker_for_delayed_jobs(): void
    {
        self::assertSame(['60 scheduler:plan', '5 scheduler:work default'], $this->lines([]));
    }

    /**
     * @param list<class-string> $jobs
     * @return list<string>
     */
    private function lines(array $jobs): array
    {
        $discovery = (new \ReflectionClass(PoolsFixtureDiscovery::class))->newInstanceWithoutConstructor();
        $discovery->classes = $jobs;
        $source = new SchedulerWorkerProcesses();
        (new \ReflectionProperty($source, 'classDiscovery'))->setValue($source, $discovery);

        return array_map(static fn (WorkerProcess $p): string => $p->line(), $source->workerProcesses());
    }
}

#[AsService]
#[AsScheduledJob(key: 'fixture.reports', cronExpression: '0 * * * *', pool: 'reports')]
final class PoolsFixtureReportJob implements ScheduledJobInterface
{
    public function handle(ScheduledJobContext $context): void
    {
    }
}

#[AsService]
#[AsScheduledJob(key: 'fixture.default', cronExpression: '0 * * * *')]
final class PoolsFixtureDefaultJob implements ScheduledJobInterface
{
    public function handle(ScheduledJobContext $context): void
    {
    }
}

final class PoolsFixtureDiscovery extends ClassDiscovery
{
    /** @var list<class-string> */
    public array $classes = [];

    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $this->classes;
    }
}
