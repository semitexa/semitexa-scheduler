<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Configuration\SchedulerConfig;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Enum\RunStatus;
use Semitexa\Scheduler\Application\Service\RunLeaseManager;
use Semitexa\Scheduler\Application\Service\SchedulerLockManager;
use Symfony\Component\Console\Output\OutputInterface;

final class SchedulerWorker
{
    private ?OutputInterface $output = null;
    private bool $shouldStop = false;

    public function __construct(
        private readonly RunLeaseManager $leaseManager,
        private readonly SchedulerLockManager $lockManager,
        private readonly ScheduledRunRepositoryInterface $runRepository,
        private readonly OverlapPolicyHandler $overlapHandler,
        private readonly RunExecutor $executor,
        private readonly RetryScheduler $retryScheduler,
        private readonly SchedulerRunHistoryRepository $historyRepository,
        private readonly SchedulerConfig $config,
    ) {}

    public function setOutput(?OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function run(?string $pool = null): void
    {
        $pool = $pool ?? $this->config->defaultPool;
        $workerId = gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));

        $this->log("Scheduler worker started (pool={$pool}, worker={$workerId})");

        while (!$this->shouldStop) {
            // Crash recovery: reclaim expired leases
            $reclaimed = $this->leaseManager->reclaimExpiredLeases(new \DateTimeImmutable());
            if ($reclaimed > 0) {
                $this->log("Reclaimed {$reclaimed} expired lease(s).");
            }

            // Clean up stale locks
            $this->lockManager->cleanup(new \DateTimeImmutable());

            // Claim next due run
            $runId = $this->leaseManager->claimNextDue($pool, $workerId);

            if ($runId === null) {
                sleep($this->config->pollIntervalSeconds);
                continue;
            }

            $run = $this->runRepository->findById($runId);
            if ($run === null) {
                $this->log("Run '{$runId}' disappeared after claim — discarding.", 'warning');
                continue;
            }

            $this->processRun($run, $workerId);
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Execute ONE run synchronously and say whether it succeeded — the seam
     * behind `scheduler:run-now --inline`. Same path as the loop: overlap
     * policy, lease heartbeat, executor, retry bookkeeping; the only
     * difference is that the caller hands the run over instead of a poll
     * finding it.
     */
    public function processSingle(ScheduledRun $run, string $workerId): bool
    {
        $this->processRun($run, $workerId);

        return $run->getStatus() === RunStatus::Succeeded->value;
    }

    private function processRun(ScheduledRun $run, string $workerId): void
    {
        $this->log("Processing run '{$run->getId()}' (job: {$run->getJobClass()})");

        $overlapResult = $this->overlapHandler->handle($run, $workerId, $this->output);

        if (!$overlapResult->proceed) {
            return;
        }

        $heartbeat = new LeaseHeartbeat(
            leaseManager: $this->leaseManager,
            lockManager: $this->lockManager,
            runId: $run->getId(),
            workerId: $workerId,
            lockKey: $overlapResult->lockAcquired ? $run->getLockKey() : null,
        );

        try {
            $result = $this->executor->execute($run, $workerId, $heartbeat, $this->output);

            if ($result->success) {
                $run->setStatus(RunStatus::Succeeded->value);
                $run->setFinishedAt(new \DateTimeImmutable());
                $run->setLeaseOwner(null);
                $run->setLeaseExpiresAt(null);
                $this->runRepository->save($run);
                $this->historyRepository->append(
                    $run->getId(), 'succeeded', 'running', RunStatus::Succeeded->value,
                    $workerId, 'Job completed successfully',
                );
                $this->log("Run '{$run->getId()}' succeeded.");
            } else {
                $error = $result->error ?? 'Unknown error';
                $retried = $this->retryScheduler->scheduleRetry($run, $workerId, $error);
                if (!$retried) {
                    $this->retryScheduler->markFailed($run, $workerId, $error);
                    $this->log("Run '{$run->getId()}' failed permanently: {$error}", 'error');
                } else {
                    $this->log("Run '{$run->getId()}' failed on attempt {$run->getAttemptCount()}, retrying.", 'warning');
                }
            }
        } finally {
            if ($overlapResult->lockAcquired && $run->getLockKey() !== null) {
                $this->lockManager->release($run->getLockKey(), $workerId);
            }
        }
    }

    private function log(string $message, string $level = 'info'): void
    {
        if ($this->output !== null) {
            $tag = match ($level) {
                'error'   => 'error',
                'warning' => 'comment',
                default   => 'info',
            };
            $this->output->writeln("<{$tag}>{$message}</{$tag}>");
        } else {
            echo $message . "\n";
        }
    }
}
