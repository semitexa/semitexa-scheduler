<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Service\LeaseHeartbeat;
use Semitexa\Scheduler\Application\Service\RunLeaseManager;
use Semitexa\Scheduler\Application\Service\SchedulerLockManager;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\SchedulerLockRepositoryInterface;
use Semitexa\Scheduler\Domain\Exception\LeaseLostException;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;

/**
 * The worker never renews a run's lease by itself, so a long job has to be
 * able to: otherwise another worker reclaims the lease mid-run and executes
 * the same job again, concurrently.
 */
final class JobLeaseRenewalTest extends TestCase
{
    #[Test]
    public function a_job_renews_its_lease_and_lock_through_its_context(): void
    {
        $runs = $this->createMock(ScheduledRunRepositoryInterface::class);
        $runs->expects(self::once())->method('renewLease')->with('run-1', 'worker-1', 300)->willReturn(true);
        $locks = $this->createMock(SchedulerLockRepositoryInterface::class);
        $locks->expects(self::once())->method('extend')->with('lock-1', 'worker-1', 120)->willReturn(true);

        $heartbeat = new LeaseHeartbeat(
            new RunLeaseManager($runs, 300),
            new SchedulerLockManager($locks, 120),
            'run-1',
            'worker-1',
            'lock-1',
        );
        $context = new ScheduledJobContext(runId: 'run-1', jobClass: 'Job', pool: 'default', renewLease: $heartbeat->tick(...));

        $context->renewLease();
    }

    #[Test]
    public function a_job_whose_lease_was_reclaimed_is_stopped_at_its_next_renewal(): void
    {
        $runs = $this->createMock(ScheduledRunRepositoryInterface::class);
        $runs->expects(self::once())->method('renewLease')->willReturn(false);
        $locks = $this->createMock(SchedulerLockRepositoryInterface::class);
        $locks->expects(self::never())->method('extend');

        $context = $this->contextWith($runs, $locks);

        $this->expectException(LeaseLostException::class);
        $this->expectExceptionMessage("lease on run 'run-1'");
        $context->renewLease();
    }

    #[Test]
    public function a_job_whose_overlap_lock_was_taken_is_stopped_at_its_next_renewal(): void
    {
        $runs = $this->createMock(ScheduledRunRepositoryInterface::class);
        $runs->method('renewLease')->willReturn(true);
        $locks = $this->createMock(SchedulerLockRepositoryInterface::class);
        $locks->expects(self::once())->method('extend')->willReturn(false);

        $context = $this->contextWith($runs, $locks);

        $this->expectException(LeaseLostException::class);
        $this->expectExceptionMessage("overlap lock 'lock-1'");
        $context->renewLease();
    }

    private function contextWith(
        ScheduledRunRepositoryInterface $runs,
        SchedulerLockRepositoryInterface $locks,
    ): ScheduledJobContext {
        $heartbeat = new LeaseHeartbeat(
            new RunLeaseManager($runs, 300),
            new SchedulerLockManager($locks, 120),
            'run-1',
            'worker-1',
            'lock-1',
        );

        return new ScheduledJobContext(runId: 'run-1', jobClass: 'Job', pool: 'default', renewLease: $heartbeat->tick(...));
    }
}
