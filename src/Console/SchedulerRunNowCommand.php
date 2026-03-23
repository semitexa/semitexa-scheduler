<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Console;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Scheduler\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Enum\OverlapPolicy;
use Semitexa\Scheduler\Enum\RunStatus;
use Semitexa\Scheduler\Enum\SourceType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'scheduler:run-now', description: 'Create an immediate run for a schedule key (operator intervention)')]
final class SchedulerRunNowCommand extends Command
{
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
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $scheduleKey = $input->getArgument('schedule-key');
        $tenantId    = $input->getOption('tenant');

        try {
            $container      = ContainerFactory::get();
            $definitionRepo = $container->get(ScheduleDefinitionRepositoryInterface::class);
            $runRepo        = $container->get(ScheduledRunRepositoryInterface::class);

            $definition = $definitionRepo->findByKey($scheduleKey);

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

            $runRepo->save($run);

            $io->success("Created immediate run '{$run->id}' for '{$scheduleKey}'.");
        } catch (\Throwable $e) {
            $io->error('scheduler:run-now failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
