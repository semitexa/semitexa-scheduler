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
use Semitexa\Scheduler\Domain\Enum\TenantScheduleMode;
use Semitexa\Tenancy\Domain\Contract\TenantRepositoryInterface;
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

    #[InjectAsReadonly]
    protected TenantRepositoryInterface $tenantRepo;

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

            // A per-tenant schedule run without --tenant ran once, tenant-blind,
            // under a lock none of its tenant runs take; an unknown id ran a
            // tenant that does not exist. An install without tenants runs it
            // once, as the planner does.
            if ($tenantId !== null && !$this->tenantRepo->exists($tenantId)) {
                $io->error("Tenant '{$tenantId}' does not exist.");
                return Command::FAILURE;
            }
            if ($tenantId === null
                && TenantScheduleMode::tryFrom($definition->getTenantMode()) === TenantScheduleMode::PerTenant
                && $this->tenantRepo->findAll() !== []
            ) {
                $io->error("Schedule '{$scheduleKey}' runs once per tenant: name the tenant with --tenant=<id>.");
                return Command::FAILURE;
            }

            $now        = new \DateTimeImmutable();
            $overlapPolicy = OverlapPolicy::from($definition->getOverlapPolicy());
            $effectiveTenantId = $tenantId ?? null;

            $lockKey = null;
            if ($overlapPolicy !== OverlapPolicy::Allow) {
                $lockKey = $effectiveTenantId !== null
                    ? "scheduler:{$scheduleKey}:tenant:{$effectiveTenantId}"
                    : "scheduler:{$scheduleKey}";
            }

            $run = new ScheduledRun();
            $run->setSourceType(SourceType::Delayed->value);
            $run->setScheduleKey($scheduleKey);
            $run->setJobClass($definition->getJobClass());
            $run->setTenantId($effectiveTenantId);
            $run->setPool($definition->getPool());
            $run->setLockKey($lockKey);
            $run->setStatus(RunStatus::Pending->value);
            $run->setScheduledFor($now);
            $run->setAvailableAt($now);
            $run->setMaxAttempts($definition->getMaxAttempts());
            $run->setRetryBackoffSeconds($definition->getRetryBackoffSeconds());

            // An inline run is saved already claimed, under its own lease. It
            // was saved pending, so a live worker for the pool could claim it
            // in between and run it a second time, and with no lease to renew
            // every renewLease() inside the job failed the attempt.
            $inlineWorkerId = null;
            $config = SchedulerConfig::create();
            if ($input->getOption('inline')) {
                $inlineWorkerId = 'run-now-inline-' . getmypid() . '-' . bin2hex(random_bytes(4));
                $run->setStatus(RunStatus::Claimed->value);
                $run->setLeaseOwner($inlineWorkerId);
                $run->setLeaseExpiresAt($now->modify('+' . $config->leaseTtlSeconds . ' seconds'));
            }

            $this->runRepo->save($run);

            if ($inlineWorkerId !== null) {
                // Rewritten by the database's clock, which the reclaim judges
                // it by: a host whose clock runs behind had its run reclaimed
                // and started again by a worker while it still ran inline.
                // Refused, the reclaim took the run already (this host's clock
                // is that far behind): running it here would run it twice.
                if (!$this->runRepo->renewLease($run->getId(), $inlineWorkerId, $config->leaseTtlSeconds)) {
                    $io->error("Run '{$run->getId()}' lost its lease before it could run inline: a worker runs it instead. Check this host's clock against the database's.");

                    return Command::FAILURE;
                }
                // Read back, as a worker reads a run it claimed: the executor
                // saves the whole row, and the PHP-clock lease still on this
                // object went back over the database's.
                $run = $this->runRepo->findById($run->getId()) ?? $run;

                return $this->runInline($run, $inlineWorkerId, $config, $io);
            }

            $io->success("Created immediate run '{$run->getId()}' for '{$scheduleKey}'.");
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
    private function runInline(ScheduledRun $run, string $workerId, SchedulerConfig $config, SymfonyStyle $io): int
    {
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

        $ok = $worker->processSingle($run, $workerId);

        if ($ok) {
            $io->success("Run '{$run->getId()}' executed inline and succeeded.");

            return Command::SUCCESS;
        }

        // The stored status, not the in-memory one: a discarded outcome wrote
        // nothing, and a delayed or retried run is still to come.
        $status = $this->runRepo->findById($run->getId())?->getStatus() ?? $run->getStatus();
        $io->error("Run '{$run->getId()}' executed inline and did not succeed (status now: {$status}). See scheduler history for the error.");

        return Command::FAILURE;
    }
}
