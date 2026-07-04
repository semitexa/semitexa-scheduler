<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\OrmManager;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Configuration\SchedulerConfig;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\SchedulerLockRepositoryInterface;
use Semitexa\Scheduler\Application\Service\RunLeaseManager;
use Semitexa\Scheduler\Application\Service\SchedulerLockManager;
use Semitexa\Scheduler\Application\Service\OverlapPolicyHandler;
use Semitexa\Scheduler\Application\Service\RetryScheduler;
use Semitexa\Scheduler\Application\Service\RunExecutor;
use Semitexa\Scheduler\Application\Service\SchedulerWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'scheduler:work', description: 'Run the scheduler worker for a given pool')]
final class SchedulerWorkCommand extends Command
{
    #[InjectAsReadonly]
    protected ScheduledRunRepositoryInterface $runRepo;

    #[InjectAsReadonly]
    protected SchedulerLockRepositoryInterface $lockRepo;

    #[InjectAsReadonly]
    protected ScheduleDefinitionRepositoryInterface $definitionRepo;

    #[InjectAsReadonly]
    protected EventDispatcherInterface $events;

    protected function configure(): void
    {
        $this->setName('scheduler:work')
             ->setDescription('Run the scheduler worker for a given pool')
             ->addArgument(
                 name:        'pool',
                 mode:        InputArgument::OPTIONAL,
                 description: 'Worker pool name (default: from SCHEDULER_DEFAULT_POOL env or "default")',
                 default:     null,
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $pool = $input->getArgument('pool');

        $io->title('Scheduler Worker');

        try {
            // CLI parity with the Swoole WorkerStart bootstrap: register the
            // ORM's default dispatcher resolver BEFORE any job runs, so every
            // scheduled job's ORM writes auto-publish their invalidation
            // signals without each job carrying its own CLI bootstrap.
            OrmManager::setDefaultEventDispatcherResolver(
                fn (): EventDispatcherInterface => $this->events,
            );

            $config      = SchedulerConfig::create();
            $historyRepo = new SchedulerRunHistoryRepository();

            $leaseManager   = new RunLeaseManager($this->runRepo, $config->leaseTtlSeconds);
            $lockManager    = new SchedulerLockManager($this->lockRepo, $config->lockTtlSeconds);
            $overlapHandler = new OverlapPolicyHandler($this->runRepo, $lockManager, $this->definitionRepo, $historyRepo);
            $executor       = new RunExecutor($this->runRepo, $historyRepo);
            $retryScheduler = new RetryScheduler($this->runRepo, $historyRepo);

            $worker = new SchedulerWorker(
                leaseManager:    $leaseManager,
                lockManager:     $lockManager,
                runRepository:   $this->runRepo,
                overlapHandler:  $overlapHandler,
                executor:        $executor,
                retryScheduler:  $retryScheduler,
                historyRepository: $historyRepo,
                config:          $config,
            );
            $worker->setOutput($output);
            $worker->run($pool);
        } catch (\Throwable $e) {
            $io->error('Scheduler worker failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
