<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Model\RetryPolicy;
use Semitexa\Scheduler\Domain\Enum\RunStatus;
use Semitexa\Scheduler\Domain\Exception\LeaseLostException;

final class RetryScheduler
{
    public function __construct(
        private readonly ScheduledRunRepositoryInterface $runRepository,
        private readonly SchedulerRunHistoryRepository $historyRepository,
    ) {}

    /**
     * Schedule a retry if within max attempts. Returns true if retried, false if terminal.
     *
     * @throws LeaseLostException when another worker has taken the run over;
     *         nothing is written then.
     */
    public function scheduleRetry(ScheduledRun $run, string $workerId, string $errorMessage): bool
    {
        $policy = new RetryPolicy($run->getMaxAttempts(), $run->getRetryBackoffSeconds());

        if (!$policy->shouldRetry($run->getAttemptCount())) {
            return false;
        }

        $owner = $run->getLeaseOwner();
        $run->setStatus(RunStatus::RetryScheduled->value);
        $run->setAvailableAt($policy->nextAvailableAt($run->getAttemptCount()));
        $run->setLastError($errorMessage);
        $run->setLeaseOwner(null);
        $run->setLeaseExpiresAt(null);
        $this->finalize($run, $owner, $workerId);

        $this->historyRepository->append(
            $run->getId(), 'retry_scheduled', 'running', RunStatus::RetryScheduled->value,
            $workerId, "Retry {$run->getAttemptCount()}/{$run->getMaxAttempts()}: {$errorMessage}",
            ['attempt_count' => $run->getAttemptCount(), 'next_available_at' => $run->getAvailableAt()?->format('c')],
        );

        return true;
    }

    /**
     * @throws LeaseLostException when another worker has taken the run over;
     *         nothing is written then.
     */
    public function markFailed(ScheduledRun $run, string $workerId, string $errorMessage): void
    {
        $owner = $run->getLeaseOwner();
        $run->setStatus(RunStatus::Failed->value);
        $run->setLastError($errorMessage);
        $run->setFinishedAt(new \DateTimeImmutable());
        $run->setLeaseOwner(null);
        $run->setLeaseExpiresAt(null);
        $this->finalize($run, $owner, $workerId);

        $this->historyRepository->append(
            $run->getId(), 'failed', 'running', RunStatus::Failed->value,
            $workerId, "Terminal failure after {$run->getAttemptCount()} attempt(s): {$errorMessage}",
            ['attempt_count' => $run->getAttemptCount()],
        );
    }

    /**
     * The outcome belongs to whoever still owns the run: a worker whose lease
     * was reclaimed must not overwrite the state its successor writes.
     */
    private function finalize(ScheduledRun $run, ?string $owner, string $workerId): void
    {
        if (!$this->runRepository->finalizeIfOwned($run, $owner)) {
            throw LeaseLostException::forRun($run->getId(), $workerId);
        }
    }
}
