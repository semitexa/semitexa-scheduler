<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Application\Service\OverlapPolicyHandler;
use Semitexa\Scheduler\Application\Service\RetryScheduler;
use Semitexa\Scheduler\Application\Service\RunExecutor;
use Semitexa\Scheduler\Application\Service\RunLeaseManager;
use Semitexa\Scheduler\Application\Service\SchedulerLockManager;
use Semitexa\Scheduler\Application\Service\SchedulerWorker;
use Semitexa\Scheduler\Configuration\SchedulerConfig;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRetentionInterface;
use Semitexa\Scheduler\Domain\Contract\SchedulerLockRepositoryInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A retention sweep that failed after some batches said only that it failed:
 * the runs its earlier batches had deleted never reached the log.
 */
final class SchedulerWorkerRetentionTest extends TestCase
{
    #[Test]
    public function a_sweep_that_fails_part_way_says_how_many_runs_went_before_it(): void
    {
        $retention = new class implements ScheduledRunRetentionInterface {
            public int $calls = 0;

            public function deleteFinishedBefore(\DateTimeImmutable $cutoff, int $limit): int
            {
                if (++$this->calls === 3) {
                    throw new \RuntimeException('Lock wait timeout exceeded');
                }

                return $limit;
            }
        };
        $runs = $this->createMock(ScheduledRunRepositoryInterface::class);
        $worker = new SchedulerWorker(
            leaseManager: new RunLeaseManager($runs, 300),
            lockManager: new SchedulerLockManager($this->createMock(SchedulerLockRepositoryInterface::class), 300),
            runRepository: $runs,
            overlapHandler: (new \ReflectionClass(OverlapPolicyHandler::class))->newInstanceWithoutConstructor(),
            executor: (new \ReflectionClass(RunExecutor::class))->newInstanceWithoutConstructor(),
            retryScheduler: (new \ReflectionClass(RetryScheduler::class))->newInstanceWithoutConstructor(),
            historyRepository: (new \ReflectionClass(SchedulerRunHistoryRepository::class))->newInstanceWithoutConstructor(),
            config: new SchedulerConfig(runRetentionDays: 30),
            retention: $retention,
        );
        $output = new BufferedOutput();
        $worker->setOutput($output);

        (new \ReflectionMethod(SchedulerWorker::class, 'sweepOldRuns'))->invoke($worker);

        self::assertSame(3, $retention->calls);
        self::assertSame("Run retention sweep failed after deleting 1000 run(s): Lock wait timeout exceeded\n", $output->fetch());
    }
}
