<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Scheduler\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Planner\CronOccurrenceCalculator;
use Semitexa\Scheduler\Planner\MisfirePolicyResolver;
use Semitexa\Scheduler\Planner\SchedulePlanner;
use Semitexa\Scheduler\Planner\TenantOccurrenceExpander;
use Semitexa\Scheduler\Service\ScheduleDefinitionRegistry;
use Semitexa\Tenancy\Identification\TenantRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'scheduler:plan', description: 'Materialize due recurring schedule occurrences into run rows')]
final class SchedulerPlanCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('scheduler:plan')
             ->setDescription('Materialize due recurring schedule occurrences into run rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Scheduler Planner');

        try {
            $container      = ContainerFactory::get();
            $definitionRepo = $container->get(ScheduleDefinitionRepositoryInterface::class);
            $runRepo        = $container->get(ScheduledRunRepositoryInterface::class);
            $tenantRepo     = $container->get(TenantRepositoryInterface::class);

            // Sync code-discovered schedules to DB
            $registry = new ScheduleDefinitionRegistry($definitionRepo);
            $registry->sync();

            $planner = new SchedulePlanner(
                definitionRepository: $definitionRepo,
                runRepository:        $runRepo,
                calculator:           new CronOccurrenceCalculator(),
                misfireResolver:      new MisfirePolicyResolver(),
                tenantExpander:       new TenantOccurrenceExpander($tenantRepo),
            );

            $now     = new \DateTimeImmutable();
            $planned = $planner->plan($now, $output);

            $io->success("Planned {$planned} run(s).");
        } catch (\Throwable $e) {
            $io->error('Scheduler plan failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
