<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\OrmManager;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\SchedulerLockRepositoryInterface;
use Semitexa\Scheduler\Application\Service\OverlapPolicyHandler;
use Semitexa\Scheduler\Application\Service\RetryScheduler;
use Semitexa\Scheduler\Application\Service\RunExecutor;
use Semitexa\Scheduler\Application\Service\RunLeaseManager;
use Semitexa\Scheduler\Application\Service\SchedulerLockManager;
use Semitexa\Scheduler\Application\Service\SchedulerWorker;
use Semitexa\Scheduler\Configuration\SchedulerConfig;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Enum\OverlapPolicy;
use Semitexa\Scheduler\Domain\Enum\RunStatus;
use Semitexa\Scheduler\Domain\Enum\SourceType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'scheduler:run-now', description: 'Create an immediate run for a schedule key (operator intervention)')]
final class SchedulerRunNowCommand extends Command
{
    #[InjectAsReadonly]
    protected ScheduleDefinitionRepositoryInterface $definitionRepo;

    #[InjectAsReadonly]
    protected ScheduledRunRepositoryInterface $runRepo;

    #[InjectAsReadonly]
    protected EventDispatcherInterface $events;

    #[InjectAsReadonly]
    protected SchedulerLockRepositoryInterface $lockRepo;

    protected function configure(): void
    {
        $this->setName('scheduler:run-now')
             ->setDescription('Create an immediate run for a schedule key (operator intervention)')
             ->addArgument(
                 name:        'schedule-key',
                 mode:        InputArgument::REQUIRED,
                 description: 'The schedule key to run immediately',
             )
             ->addOption(
                 name:        'tenant',
                 shortcut:    't',
                 mode:        InputOption::VALUE_OPTIONAL,
                 description: 'Tenant ID for tenant-bound runs',
             )
             ->addOption(
                 name:        'inline',
                 mode:        InputOption::VALUE_NONE,
                 description: 'Execute the run synchronously and exit by its outcome - no worker needed. For operator one-offs and test stands.',
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $scheduleKey = $input->getArgument('schedule-key');
        $tenantId    = $input->getOption('tenant');

        try {
            // CLI parity with the Swoole WorkerStart bootstrap: this
            // command writes a run row; the write publishes like any other.
            OrmManager::setDefaultEventDispatcherResolver(
                fn (): EventDispatcherInterface => $this->events,
            );

            $definition = $this->definitionRepo->findByKey($scheduleKey);

            if ($definition === null) {
                $io->error("Schedule definition '{$scheduleKey}' not found.");
                return Command::FAILURE;
            }

            $now        = new \DateTimeImmutable();
            $overlapPolicy = OverlapPolicy::from($definition->overlapPolicy);
            $effectiveTenantId = $tenantId ?? null;

            $lockKey = null;
            if ($overlapPolicy !== OverlapPolicy::Allow) {
                $lockKey = $effectiveTenantId !== null
                    ? "scheduler:{$scheduleKey}:tenant:{$effectiveTenantId}"
                    : "scheduler:{$scheduleKey}";
            }

            $run = new ScheduledRun();
            $run->sourceType = SourceType::Delayed->value;
            $run->scheduleKey = $scheduleKey;
            $run->jobClass = $definition->jobClass;
            $run->tenantId = $effectiveTenantId;
            $run->pool = $definition->pool;
            $run->lockKey = $lockKey;
            $run->status = RunStatus::Pending->value;
            $run->scheduledFor = $now;
            $run->availableAt = $now;
            $run->maxAttempts = $definition->maxAttempts;
            $run->retryBackoffSeconds = $definition->retryBackoffSeconds;

            $this->runRepo->save($run);

            if ($input->getOption('inline')) {
                return $this->runInline($run, $io);
            }

            $io->success("Created immediate run '{$run->id}' for '{$scheduleKey}'.");
        } catch (\Throwable $e) {
            $io->error('scheduler:run-now failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Drive the just-created run through the SAME machinery the worker loop
     * uses - overlap policy, lease heartbeat, executor, retry bookkeeping,
     * Observatory journal - and answer with its real outcome. A failed job
     * exits non-zero, which is the whole point for scripts and test stands.
     */
    private function runInline(ScheduledRun $run, SymfonyStyle $io): int
    {
        $config      = SchedulerConfig::create();
        $historyRepo = new SchedulerRunHistoryRepository();
        $lockManager = new SchedulerLockManager($this->lockRepo, $config->lockTtlSeconds);

        $worker = new SchedulerWorker(
            leaseManager:      new RunLeaseManager($this->runRepo, $config->leaseTtlSeconds),
            lockManager:       $lockManager,
            runRepository:     $this->runRepo,
            overlapHandler:    new OverlapPolicyHandler($this->runRepo, $lockManager, $this->definitionRepo, $historyRepo),
            executor:          new RunExecutor($this->runRepo, $historyRepo),
            retryScheduler:    new RetryScheduler($this->runRepo, $historyRepo),
            historyRepository: $historyRepo,
            config:            $config,
        );

        $ok = $worker->processSingle($run, 'run-now-inline-' . getmypid());

        if ($ok) {
            $io->success("Run '{$run->id}' executed inline and succeeded.");

            return Command::SUCCESS;
        }

        $io->error("Run '{$run->id}' executed inline and FAILED (status: {$run->status}). See scheduler history for the error.");

        return Command::FAILURE;
    }
}
