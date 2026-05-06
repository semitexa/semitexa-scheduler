<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Domain\Model\OverlapHandleResult;

use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Enum\OverlapPolicy;
use Semitexa\Scheduler\Domain\Enum\RunStatus;
use Semitexa\Scheduler\Application\Service\SchedulerLockManager;
use Symfony\Component\Console\Output\OutputInterface;

final class OverlapPolicyHandler
{
    public function __construct(
        private readonly ScheduledRunRepositoryInterface $runRepository,
        private readonly SchedulerLockManager $lockManager,
        private readonly ScheduleDefinitionRepositoryInterface $definitionRepository,
        private readonly SchedulerRunHistoryRepository $historyRepository,
    ) {}

    public function handle(
        ScheduledRun $run,
        string $workerId,
        ?OutputInterface $output = null,
    ): OverlapHandleResult {
        if ($run->lockKey === null) {
            return new OverlapHandleResult(proceed: true, lockAcquired: false);
        }

        $acquired = $this->lockManager->acquire($run->lockKey, $run->id, $workerId);

        if ($acquired) {
            return new OverlapHandleResult(proceed: true, lockAcquired: true);
        }

        // Lock already held — apply policy
        $policy = $this->resolvePolicy($run);

        if ($policy === OverlapPolicy::Delay) {
            $delay = max(30, $run->retryBackoffSeconds > 0 ? $run->retryBackoffSeconds : 30);
            $run->status = RunStatus::RetryScheduled->value;
            $run->availableAt = (new \DateTimeImmutable())->modify("+{$delay} seconds");
            $run->leaseOwner = null;
            $run->leaseExpiresAt = null;
            $this->runRepository->save($run);
            $this->historyRepository->append(
                $run->id, 'overlap_delayed', 'claimed', RunStatus::RetryScheduled->value,
                $workerId, "Lock '{$run->lockKey}' held — retrying in {$delay}s",
            );
            $output?->writeln("<comment>Run '{$run->id}' delayed (lock held), retry in {$delay}s.</comment>");
            return new OverlapHandleResult(proceed: false, lockAcquired: false);
        }

        // Default: Skip
        $run->status = RunStatus::SkippedOverlap->value;
        $run->leaseOwner = null;
        $run->leaseExpiresAt = null;
        $this->runRepository->save($run);
        $this->historyRepository->append(
            $run->id, 'skipped_overlap', 'claimed', RunStatus::SkippedOverlap->value,
            $workerId, "Lock '{$run->lockKey}' already held",
        );
        $output?->writeln("<comment>Run '{$run->id}' skipped (lock held).</comment>");
        return new OverlapHandleResult(proceed: false, lockAcquired: false);
    }

    private function resolvePolicy(ScheduledRun $run): OverlapPolicy
    {
        if ($run->scheduleKey !== null) {
            $definition = $this->definitionRepository->findByKey($run->scheduleKey);
            if ($definition !== null) {
                return OverlapPolicy::from($definition->overlapPolicy);
            }
        }
        return OverlapPolicy::Skip;
    }
}
