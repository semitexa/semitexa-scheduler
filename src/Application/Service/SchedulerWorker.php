<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Configuration\SchedulerConfig;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRetentionInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Enum\RunStatus;
use Semitexa\Scheduler\Domain\Exception\LeaseLostException;
use Semitexa\Scheduler\Application\Service\RunLeaseManager;
use Semitexa\Scheduler\Application\Service\SchedulerLockManager;
use Symfony\Component\Console\Output\OutputInterface;

final class SchedulerWorker
{
    private ?OutputInterface $output = null;
    private bool $shouldStop = false;
    private int $nextSweepAt = 0;

    public function __construct(
        private readonly RunLeaseManager $leaseManager,
        private readonly SchedulerLockManager $lockManager,
        private readonly ScheduledRunRepositoryInterface $runRepository,
        private readonly OverlapPolicyHandler $overlapHandler,
        private readonly RunExecutor $executor,
        private readonly RetryScheduler $retryScheduler,
        private readonly SchedulerRunHistoryRepository $historyRepository,
        private readonly SchedulerConfig $config,
        private readonly ?ScheduledRunRetentionInterface $retention = null,
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

            $this->sweepOldRuns();

            // A stop asked while this pass swept and reclaimed (SIGTERM lands
            // anywhere): claiming now would start a whole new job after it.
            if ($this->shouldStop) {
                break;
            }

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

        $this->log('Scheduler worker stopped');
    }

    /**
     * Finished runs older than SCHEDULER_RETENTION_DAYS go, with their history,
     * at most once an hour per worker: nothing deleted them, and a job planned
     * every minute added a run and its history rows each time, forever.
     */
    private function sweepOldRuns(): void
    {
        if ($this->retention === null || $this->config->runRetentionDays <= 0 || time() < $this->nextSweepAt) {
            return;
        }
        $this->nextSweepAt = time() + 3600;

        $cutoff = (new \DateTimeImmutable())->modify("-{$this->config->runRetentionDays} days");
        try {
            $deleted = 0;
            for ($batch = 0; $batch < 20; $batch++) {
                $n = $this->retention->deleteFinishedBefore($cutoff, 500);
                $deleted += $n;
                if ($n < 500) {
                    break;
                }
            }
            if ($deleted > 0) {
                $this->log("Deleted {$deleted} finished run(s) older than {$this->config->runRetentionDays} days.");
            }
        } catch (\Throwable $e) {
            // Retention is housekeeping: it never stops the worker.
            $this->log("Run retention sweep failed: {$e->getMessage()}", 'warning');
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
        // The in-memory status says succeeded even when the outcome was
        // discarded (the lease was lost meanwhile): only a recorded success
        // counts.
        return $this->processRun($run, $workerId)
            && $run->getStatus() === RunStatus::Succeeded->value;
    }

    /** @return bool false when the outcome was discarded because the lease was lost */
    private function processRun(ScheduledRun $run, string $workerId): bool
    {
        $this->log("Processing run '{$run->getId()}' (job: {$run->getJobClass()})");

        $overlapResult = $this->overlapHandler->handle($run, $workerId, $this->output);

        if (!$overlapResult->proceed) {
            return true;
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
                $owner = $run->getLeaseOwner();
                $run->setStatus(RunStatus::Succeeded->value);
                $run->setFinishedAt(new \DateTimeImmutable());
                $run->setLeaseOwner(null);
                $run->setLeaseExpiresAt(null);
                if (!$this->runRepository->finalizeIfOwned($run, $owner)) {
                    throw LeaseLostException::forRun($run->getId(), $workerId);
                }
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
        } catch (LeaseLostException $e) {
            // Another worker took the run over while this one was executing
            // it; the outcome is theirs to record, so this one writes nothing.
            $this->log("Run '{$run->getId()}' outcome discarded: {$e->getMessage()}", 'warning');

            return false;
        } finally {
            if ($overlapResult->lockAcquired && $run->getLockKey() !== null) {
                $this->lockManager->release($run->getLockKey(), $workerId);
            }
        }

        return true;
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
