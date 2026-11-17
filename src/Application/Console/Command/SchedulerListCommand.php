<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
use Semitexa\Scheduler\Application\Service\CronOccurrenceCalculator;
use Semitexa\Scheduler\Application\Service\ScheduleDefinitionRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'scheduler:list', description: 'List all enabled schedule definitions and their next due time')]
final class SchedulerListCommand extends Command
{
    #[InjectAsReadonly]
    protected ScheduleDefinitionRepositoryInterface $definitionRepo;

    #[InjectAsReadonly]
    protected ScheduleDefinitionRegistry $registry;

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
            $calculator = new CronOccurrenceCalculator();

            $definitions = $this->definitionRepo->findAllEnabled();

            if ($definitions === []) {
                $io->note('No enabled schedule definitions found.');
                return Command::SUCCESS;
            }

            $rows = [];
            $now  = new \DateTimeImmutable();
            // An enabled row the planner passes over (its job class deleted, its
            // key renamed) is kept on purpose, for deploys and rollbacks: it must
            // not show a Next Due time it will never meet.
            $notPlanned = $this->registry->notPlanned($definitions);

            foreach ($definitions as $def) {
                $nextStr = isset($notPlanned[$def->getScheduleKey()])
                    ? 'not planned: ' . $notPlanned[$def->getScheduleKey()]
                    : $this->nextDue($calculator, $def, $now);

                $rows[] = [
                    $def->getScheduleKey(),
                    $def->getCronExpression(),
                    $nextStr,
                    $def->getPool(),
                    $def->getOverlapPolicy(),
                    $def->getMisfirePolicy(),
                    $def->getTenantMode(),
                    $def->getPlanningCursorAt() !== null ? $def->getPlanningCursorAt()->format('Y-m-d H:i:s') . ' UTC' : 'never',
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

    private function nextDue(CronOccurrenceCalculator $calculator, ScheduleDefinition $def, \DateTimeImmutable $now): string
    {
        try {
            $next = $calculator->getNextOccurrence($def->getCronExpression(), $now, $def->getTimezone());
            // The calculator answers in UTC; it was printed under the
            // row's timezone label, so a 03:00 Kyiv job read 00:00.
            return $def->getTimezone() === 'UTC'
                ? $next->format('Y-m-d H:i:s') . ' (UTC)'
                : $next->setTimezone(new \DateTimeZone($def->getTimezone()))->format('Y-m-d H:i:s')
                    . ' (' . $def->getTimezone() . '; '
                    . $next->format($next->format('Y-m-d') === $next->setTimezone(new \DateTimeZone($def->getTimezone()))->format('Y-m-d') ? 'H:i' : 'Y-m-d H:i')
                    . ' UTC)';
        } catch (\Throwable) {
            return '(invalid expression)';
        }
    }
}
