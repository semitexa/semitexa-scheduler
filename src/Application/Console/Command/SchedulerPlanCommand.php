<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\OrmManager;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Application\Service\CronOccurrenceCalculator;
use Semitexa\Scheduler\Application\Service\MisfirePolicyResolver;
use Semitexa\Scheduler\Application\Service\SchedulePlanner;
use Semitexa\Scheduler\Application\Service\TenantOccurrenceExpander;
use Semitexa\Scheduler\Application\Service\ScheduleDefinitionRegistry;
use Semitexa\Tenancy\Domain\Contract\TenantRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'scheduler:plan', description: 'Materialize due recurring schedule occurrences into run rows')]
final class SchedulerPlanCommand extends Command
{
    #[InjectAsReadonly]
    protected ScheduleDefinitionRepositoryInterface $definitionRepo;

    #[InjectAsReadonly]
    protected ScheduledRunRepositoryInterface $runRepo;

    /**
     * Resolves to the environment-configured repository
     * (`EnvironmentTenantRepository`); tenancy is a hard dependency of this
     * package, so per-tenant expansion always has a repository to fan out
     * over — the old "could not be resolved" degradation path is gone.
     */
    #[InjectAsReadonly]
    protected TenantRepositoryInterface $tenantRepo;

    #[InjectAsReadonly]
    protected ScheduleDefinitionRegistry $registry;

    #[InjectAsReadonly]
    protected EventDispatcherInterface $events;

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
            // CLI parity with the Swoole WorkerStart bootstrap (same as
            // scheduler:work): the planner's own ORM writes — definition
            // sync + run materialization — publish their invalidation
            // signals like any other write.
            OrmManager::setDefaultEventDispatcherResolver(
                fn (): EventDispatcherInterface => $this->events,
            );

            // Sync code-discovered schedules to DB
            $this->registry->sync();

            $planner = new SchedulePlanner(
                definitionRepository: $this->definitionRepo,
                runRepository:        $this->runRepo,
                calculator:           new CronOccurrenceCalculator(),
                misfireResolver:      new MisfirePolicyResolver(),
                tenantExpander:       new TenantOccurrenceExpander($this->tenantRepo),
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
