<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Worker;

use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Value\RetryPolicy;
use Semitexa\Scheduler\Enum\RunStatus;

final class RetryScheduler
{
    public function __construct(
        private readonly ScheduledRunRepositoryInterface $runRepository,
        private readonly SchedulerRunHistoryRepository $historyRepository,
    ) {}

    /**
     * Schedule a retry if within max attempts. Returns true if retried, false if terminal.
     */
    public function scheduleRetry(ScheduledRun $run, string $workerId, string $errorMessage): bool
    {
        $policy = new RetryPolicy($run->maxAttempts, $run->retryBackoffSeconds);

        if (!$policy->shouldRetry($run->attemptCount)) {
            return false;
        }

        $run->status = RunStatus::RetryScheduled->value;
        $run->availableAt = $policy->nextAvailableAt($run->attemptCount);
        $run->lastError = $errorMessage;
        $run->leaseOwner = null;
        $run->leaseExpiresAt = null;
        $this->runRepository->save($run);

        $this->historyRepository->append(
            $run->id, 'retry_scheduled', 'running', RunStatus::RetryScheduled->value,
            $workerId, "Retry {$run->attemptCount}/{$run->maxAttempts}: {$errorMessage}",
            ['attempt_count' => $run->attemptCount, 'next_available_at' => $run->availableAt?->format('c')],
        );

        return true;
    }

    public function markFailed(ScheduledRun $run, string $workerId, string $errorMessage): void
    {
        $run->status = RunStatus::Failed->value;
        $run->lastError = $errorMessage;
        $run->finishedAt = new \DateTimeImmutable();
        $run->leaseOwner = null;
        $run->leaseExpiresAt = null;
        $this->runRepository->save($run);

        $this->historyRepository->append(
            $run->id, 'failed', 'running', RunStatus::Failed->value,
            $workerId, "Terminal failure after {$run->attemptCount} attempt(s): {$errorMessage}",
            ['attempt_count' => $run->attemptCount],
        );
    }
}
