<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Planner;

use Semitexa\Scheduler\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Value\ScheduledOccurrence;
use Semitexa\Scheduler\Enum\MisfirePolicy;
use Semitexa\Scheduler\Enum\OverlapPolicy;
use Semitexa\Scheduler\Enum\RunStatus;
use Semitexa\Scheduler\Enum\SourceType;
use Semitexa\Scheduler\Enum\TenantScheduleMode;
use Symfony\Component\Console\Output\OutputInterface;

final class SchedulePlanner
{
    public function __construct(
        private readonly ScheduleDefinitionRepositoryInterface $definitionRepository,
        private readonly ScheduledRunRepositoryInterface $runRepository,
        private readonly CronOccurrenceCalculator $calculator,
        private readonly MisfirePolicyResolver $misfireResolver,
        private readonly TenantOccurrenceExpander $tenantExpander,
    ) {}

    public function plan(\DateTimeImmutable $now, ?OutputInterface $output = null): int
    {
        $total = 0;
        foreach ($this->definitionRepository->findAllEnabled() as $definition) {
            $total += $this->planDefinition($definition, $now, $output);
        }
        return $total;
    }

    private function planDefinition(ScheduleDefinition $definition, \DateTimeImmutable $now, ?OutputInterface $output): int
    {
        $from = $definition->planningCursorAt ?? $now->modify('-1 minute');

        $occurrences = $this->calculator->getOccurrencesBetween(
            expression: $definition->cronExpression,
            from: $from,
            until: $now,
            timezone: $definition->timezone,
        );

        if ($occurrences === []) {
            return 0;
        }

        $toSchedule = $this->misfireResolver->resolve(
            missedOccurrences: $occurrences,
            policy: MisfirePolicy::from($definition->misfirePolicy),
            maxCatchUpRuns: $definition->maxCatchUpRuns,
        );

        $tenantMode = TenantScheduleMode::from($definition->tenantMode);
        $planned = 0;

        foreach ($toSchedule as $scheduledFor) {
            $base = new ScheduledOccurrence(
                scheduleKey: $definition->scheduleKey,
                scheduledFor: $scheduledFor,
            );
            foreach ($this->tenantExpander->expand($base, $tenantMode) as $occurrence) {
                $planned += $this->materializeRun($definition, $occurrence, $output);
            }
        }

        // Advance cursor to the last computed occurrence (whether or not runs were created)
        $this->definitionRepository->advancePlanningCursor(
            $definition->scheduleKey,
            $occurrences[count($occurrences) - 1],
        );

        return $planned;
    }

    private function materializeRun(ScheduleDefinition $definition, ScheduledOccurrence $occurrence, ?OutputInterface $output): int
    {
        $occurrenceKey = $occurrence->occurrenceKey();

        if ($this->runRepository->findByOccurrenceKey($occurrenceKey) !== null) {
            return 0; // Idempotency: already planned
        }

        $overlapPolicy = OverlapPolicy::from($definition->overlapPolicy);
        $lockKey = $overlapPolicy !== OverlapPolicy::Allow ? $occurrence->lockKey() : null;

        $run = new ScheduledRun();
        $run->sourceType = SourceType::Recurring->value;
        $run->scheduleKey = $definition->scheduleKey;
        $run->occurrenceKey = $occurrenceKey;
        $run->jobClass = $definition->jobClass;
        $run->tenantId = $occurrence->tenantId;
        $run->pool = $definition->pool;
        $run->lockKey = $lockKey;
        $run->status = RunStatus::Pending->value;
        $run->scheduledFor = $occurrence->scheduledFor;
        $run->availableAt = $occurrence->scheduledFor;
        $run->maxAttempts = $definition->maxAttempts;
        $run->retryBackoffSeconds = $definition->retryBackoffSeconds;

        $this->runRepository->save($run);

        $output?->writeln(sprintf(
            '  Planned %s for <info>%s</info> at %s',
            $run->id,
            $definition->scheduleKey,
            $occurrence->scheduledFor->format('Y-m-d H:i:s'),
        ));

        return 1;
    }
}
