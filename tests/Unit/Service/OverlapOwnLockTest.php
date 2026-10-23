<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Application\Service\OverlapPolicyHandler;
use Semitexa\Scheduler\Application\Service\SchedulerLockManager;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\SchedulerLockRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Model\SchedulerLock;
use Semitexa\Scheduler\Tests\Support\RepositoryHarness;

/**
 * A retry the lease reclaim requeued can find the lock its own lapsed attempt
 * still holds: the lock may outlive the lease by a rounded second, or by a
 * longer SCHEDULER_LOCK_TTL. Under `skip` it was skipped as an overlap with
 * itself, which is final, and the retry was lost.
 */
final class OverlapOwnLockTest extends TestCase
{
    private const RUN_ID = '0192f0a0-0000-7000-8000-000000000001';

    #[Test]
    public function a_run_that_finds_its_own_lock_waits_instead_of_being_skipped(): void
    {
        $run = $this->claimedRun();

        $result = $this->handler(holderRunId: self::RUN_ID)->handle($run, 'worker-2');

        self::assertFalse($result->proceed);
        self::assertSame('retry_scheduled', $run->getStatus());
        self::assertNotNull($run->getAvailableAt());
    }

    #[Test]
    public function a_run_that_finds_another_runs_lock_is_still_skipped(): void
    {
        $run = $this->claimedRun();

        $this->handler(holderRunId: '0192f0a0-0000-7000-8000-000000000002')->handle($run, 'worker-2');

        self::assertSame('skipped_overlap', $run->getStatus());
    }

    private function claimedRun(): ScheduledRun
    {
        $run = new ScheduledRun();
        $run->setId(self::RUN_ID);
        $run->setScheduleKey('report');
        $run->setLockKey('scheduler:report');
        $run->setStatus('claimed');

        return $run;
    }

    private function handler(string $holderRunId): OverlapPolicyHandler
    {
        $locks = $this->createMock(SchedulerLockRepositoryInterface::class);
        $locks->method('acquire')->willReturn(false);
        $locks->method('findByKey')->willReturn(new SchedulerLock(lockKey: 'scheduler:report', runId: $holderRunId, workerId: 'worker-1'));

        $definition = new ScheduleDefinition();
        $definition->setOverlapPolicy('skip');
        $definitions = $this->createMock(ScheduleDefinitionRepositoryInterface::class);
        $definitions->method('findByKey')->willReturn($definition);

        $runs = $this->createMock(ScheduledRunRepositoryInterface::class);
        $runs->expects(self::once())->method('save');

        $orm = RepositoryHarness::sqliteOrm();
        $orm->getAdapter()->execute(
            'CREATE TABLE scheduler_run_history (
                id BLOB PRIMARY KEY,
                run_id BLOB NOT NULL,
                event_type TEXT NOT NULL,
                from_status TEXT,
                to_status TEXT,
                worker_id TEXT,
                message TEXT,
                context_json TEXT,
                created_at TEXT,
                updated_at TEXT
            )',
        );

        return new OverlapPolicyHandler(
            $runs,
            new SchedulerLockManager($locks, 300),
            $definitions,
            RepositoryHarness::repository(SchedulerRunHistoryRepository::class, $orm),
        );
    }
}
