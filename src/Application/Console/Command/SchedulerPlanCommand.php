<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Application\Service\CronOccurrenceCalculator;
use Semitexa\Scheduler\Application\Service\MisfirePolicyResolver;
use Semitexa\Scheduler\Application\Service\SchedulePlanner;
use Semitexa\Scheduler\Application\Service\TenantOccurrenceExpander;
use Semitexa\Scheduler\Application\Service\ScheduleDefinitionRegistry;
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
            $tenantRepo     = null;
            if (interface_exists(TenantRepositoryInterface::class)) {
                try {
                    $tenantRepo = $container->get(TenantRepositoryInterface::class);
                } catch (\Throwable $e) {
                    $io->warning(
                        'Tenant repository could not be resolved; continuing without tenant expansion. '
                        . 'Please check container configuration for '
                        . TenantRepositoryInterface::class
                        . '. Error: '
                        . $e->getMessage()
                    );
                    $tenantRepo = null;
                }
            }

            // Sync code-discovered schedules to DB
            $registry = $container->get(ScheduleDefinitionRegistry::class);
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
