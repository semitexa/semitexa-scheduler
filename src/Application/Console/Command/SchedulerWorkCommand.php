<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
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
            $container      = ContainerFactory::get();
            $config         = SchedulerConfig::create();
            $runRepo        = $container->get(ScheduledRunRepositoryInterface::class);
            $lockRepo       = $container->get(SchedulerLockRepositoryInterface::class);
            $definitionRepo = $container->get(ScheduleDefinitionRepositoryInterface::class);
            try {
                $historyRepo = $container->get(SchedulerRunHistoryRepository::class);
            } catch (\Throwable) {
                $historyRepo = new SchedulerRunHistoryRepository();
            }

            $leaseManager   = new RunLeaseManager($runRepo, $config->leaseTtlSeconds);
            $lockManager    = new SchedulerLockManager($lockRepo, $config->lockTtlSeconds);
            $overlapHandler = new OverlapPolicyHandler($runRepo, $lockManager, $definitionRepo, $historyRepo);
            $executor       = new RunExecutor($runRepo, $historyRepo);
            $retryScheduler = new RetryScheduler($runRepo, $historyRepo);

            $worker = new SchedulerWorker(
                leaseManager:    $leaseManager,
                lockManager:     $lockManager,
                runRepository:   $runRepo,
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
