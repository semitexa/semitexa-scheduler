<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Worker;

use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Configuration\SchedulerConfig;
use Semitexa\Scheduler\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Enum\RunStatus;
use Semitexa\Scheduler\Lease\RunLeaseManager;
use Semitexa\Scheduler\Lock\SchedulerLockManager;
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

    private function processRun(ScheduledRun $run, string $workerId): void
    {
        $this->log("Processing run '{$run->id}' (job: {$run->jobClass})");

        $overlapResult = $this->overlapHandler->handle($run, $workerId, $this->output);

        if (!$overlapResult->proceed) {
            return;
        }

        $heartbeat = new LeaseHeartbeat(
            leaseManager: $this->leaseManager,
            lockManager: $this->lockManager,
            runId: $run->id,
            workerId: $workerId,
            lockKey: $overlapResult->lockAcquired ? $run->lockKey : null,
        );

        try {
            $result = $this->executor->execute($run, $workerId, $heartbeat, $this->output);

            if ($result->success) {
                $run->status = RunStatus::Succeeded->value;
                $run->finishedAt = new \DateTimeImmutable();
                $run->leaseOwner = null;
                $run->leaseExpiresAt = null;
                $this->runRepository->save($run);
                $this->historyRepository->append(
                    $run->id, 'succeeded', 'running', RunStatus::Succeeded->value,
                    $workerId, 'Job completed successfully',
                );
                $this->log("Run '{$run->id}' succeeded.");
            } else {
                $error = $result->error ?? 'Unknown error';
                $retried = $this->retryScheduler->scheduleRetry($run, $workerId, $error);
                if (!$retried) {
                    $this->retryScheduler->markFailed($run, $workerId, $error);
                    $this->log("Run '{$run->id}' failed permanently: {$error}", 'error');
                } else {
                    $this->log("Run '{$run->id}' failed on attempt {$run->attemptCount}, retrying.", 'warning');
                }
            }
        } finally {
            if ($overlapResult->lockAcquired && $run->lockKey !== null) {
                $this->lockManager->release($run->lockKey, $workerId);
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
