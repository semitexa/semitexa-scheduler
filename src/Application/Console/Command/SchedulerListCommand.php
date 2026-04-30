<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Application\Service\CronOccurrenceCalculator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'scheduler:list', description: 'List all enabled schedule definitions and their next due time')]
final class SchedulerListCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('scheduler:list')
             ->setDescription('List all enabled schedule definitions and their next due time');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Schedule Definitions');

        try {
            $container      = ContainerFactory::get();
            $definitionRepo = $container->get(ScheduleDefinitionRepositoryInterface::class);
            $calculator     = new CronOccurrenceCalculator();

            $definitions = $definitionRepo->findAllEnabled();

            if ($definitions === []) {
                $io->note('No enabled schedule definitions found.');
                return Command::SUCCESS;
            }

            $rows = [];
            $now  = new \DateTimeImmutable();

            foreach ($definitions as $def) {
                try {
                    $next = $calculator->getNextOccurrence($def->cronExpression, $now, $def->timezone);
                    $nextStr = $next->format('Y-m-d H:i:s') . ' (' . $def->timezone . ')';
                } catch (\Throwable) {
                    $nextStr = '(invalid expression)';
                }

                $rows[] = [
                    $def->scheduleKey,
                    $def->cronExpression,
                    $nextStr,
                    $def->pool,
                    $def->overlapPolicy,
                    $def->misfirePolicy,
                    $def->tenantMode,
                    $def->planningCursorAt?->format('Y-m-d H:i:s') ?? 'never',
                ];
            }

            $io->table(
                ['Key', 'Cron', 'Next Due', 'Pool', 'Overlap', 'Misfire', 'Tenant Mode', 'Last Planned'],
                $rows,
            );
        } catch (\Throwable $e) {
            $io->error('scheduler:list failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
