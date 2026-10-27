<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Model\ScheduledOccurrence;
use Semitexa\Scheduler\Domain\Enum\MisfirePolicy;
use Semitexa\Scheduler\Domain\Enum\OverlapPolicy;
use Semitexa\Scheduler\Domain\Enum\RunStatus;
use Semitexa\Scheduler\Domain\Enum\SourceType;
use Semitexa\Scheduler\Domain\Enum\TenantScheduleMode;
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

    /**
     * @param list<string>|null $declaredKeys keys the code still declares; a
     *        row with any other key is passed over (the sync says so). Null
     *        (discovery that found no job is more likely broken than right)
     *        plans every enabled row; an empty list plans none: jobs were
     *        found, and none of them declares a usable key.
     */
    public function plan(\DateTimeImmutable $now, ?OutputInterface $output = null, ?array $declaredKeys = null): int
    {
        $total = 0;
        $declared = $declaredKeys === null ? null : array_flip($declaredKeys);
        foreach ($this->definitionRepository->findAllEnabled() as $definition) {
            if ($declared !== null && !isset($declared[$definition->getScheduleKey()])) {
                continue;
            }
            // A schedule whose job class is gone would fail every run. It is
            // passed over, not disabled, so it plans again once the class is
            // back (a deploy caught mid-sync, a package reinstalled). The sync
            // that scheduler:plan runs first says so.
            if (!class_exists($definition->getJobClass())) {
                continue;
            }
            // One broken schedule (a policy typo, a bad cron or timezone) is
            // reported and passed over: it used to fail the whole plan, and
            // every schedule after it in key order stopped running. What this
            // hides: scheduler:plan still exits 0 for it, so this error entry
            // and the output line are the only trace, and the entry carries
            // the exception for that reason.
            try {
                $total += $this->planDefinition($definition, $now, $output);
            } catch (\ValueError | \InvalidArgumentException | \DateInvalidTimeZoneException $e) {
                $message = "Schedule '{$definition->getScheduleKey()}' was not planned: {$e->getMessage()}";
                $output?->writeln("<error>{$message}</error>");
                StaticLoggerBridge::error('scheduler', $message, [
                    'schedule_key' => $definition->getScheduleKey(),
                    'exception' => $e,
                ]);
            }
        }
        return $total;
    }

    private function planDefinition(ScheduleDefinition $definition, \DateTimeImmutable $now, ?OutputInterface $output): int
    {
        // Every policy is read before anything is calculated: a typo found
        // halfway through logged a "dropped" warning that grew each minute.
        $policy = MisfirePolicy::from($definition->getMisfirePolicy());
        OverlapPolicy::from($definition->getOverlapPolicy());
        $tenantMode = TenantScheduleMode::from($definition->getTenantMode());
        // A recurring schedule has nowhere to name its tenant, and this mode
        // was planned as global: the job ran tenant-blind.
        if ($tenantMode === TenantScheduleMode::ExplicitTenant) {
            throw new \InvalidArgumentException("tenantMode 'explicit_tenant' cannot name a tenant for a recurring schedule; use 'per_tenant', or dispatch the job with a tenantId.");
        }

        $from = $definition->getPlanningCursorAt() ?? $now->modify('-1 minute');

        $occurrences = $this->calculator->getOccurrencesBetween(
            expression: $definition->getCronExpression(),
            from: $from,
            until: $now,
            timezone: $definition->getTimezone(),
        );

        if ($occurrences === []) {
            return 0;
        }

        $toSchedule = $this->misfireResolver->resolve(
            occurrences: $occurrences,
            policy: $policy,
            now: $now,
            maxCatchUpRuns: $definition->getMaxCatchUpRuns(),
        );
        // A set, not in_array: an uncapped catch_up after a week down is ~10 000
        // occurrences, and comparing each with each held the planner for seconds.
        $kept = array_fill_keys(array_map(static fn (\DateTimeImmutable $at): int => $at->getTimestamp(), $toSchedule), true);
        $this->reportDropped($definition, $policy, array_values(array_filter(
            $occurrences,
            static fn (\DateTimeImmutable $at): bool => !isset($kept[$at->getTimestamp()]),
        )), $now, $output);

        $planned = 0;

        foreach ($toSchedule as $scheduledFor) {
            $base = new ScheduledOccurrence(
                scheduleKey: $definition->getScheduleKey(),
                scheduledFor: $scheduledFor,
            );
            foreach ($this->tenantExpander->expand($base, $tenantMode) as $occurrence) {
                $planned += $this->materializeRun($definition, $occurrence, $output);
            }
        }

        // Advance cursor to the last computed occurrence (whether or not runs were created)
        $this->definitionRepository->advancePlanningCursor(
            $definition->getScheduleKey(),
            $occurrences[count($occurrences) - 1],
        );

        return $planned;
    }

    /**
     * Say what the misfire policy dropped. A run lost to a restart gap just
     * over the on-time threshold otherwise looks exactly like a schedule with
     * nothing due. One entry per schedule, however long the outage.
     *
     * @param list<\DateTimeImmutable> $dropped sorted ascending
     */
    private function reportDropped(ScheduleDefinition $definition, MisfirePolicy $policy, array $dropped, \DateTimeImmutable $now, ?OutputInterface $output): void
    {
        if ($dropped === []) {
            return;
        }

        $oldest = $dropped[0];
        $newest = $dropped[count($dropped) - 1];
        $late = static fn (\DateTimeImmutable $at): int => $now->getTimestamp() - $at->getTimestamp();
        $key = $definition->getScheduleKey();
        $message = count($dropped) === 1
            ? sprintf("Schedule '%s' (misfirePolicy %s) dropped 1 missed occurrence: %s, %d s late.", $key, $policy->value, $oldest->format('Y-m-d H:i:s'), $late($oldest))
            : sprintf(
                "Schedule '%s' (misfirePolicy %s) dropped %d missed occurrences: %s (%d s late) to %s (%d s late).",
                $key,
                $policy->value,
                count($dropped),
                $oldest->format('Y-m-d H:i:s'),
                $late($oldest),
                $newest->format('Y-m-d H:i:s'),
                $late($newest),
            );

        $output?->writeln('  ' . $message);
        StaticLoggerBridge::warning('scheduler', $message, [
            'schedule_key' => $key,
            'misfire_policy' => $policy->value,
            'dropped' => count($dropped),
            'oldest' => $oldest->format(DATE_ATOM),
            'oldest_late_seconds' => $late($oldest),
            'newest' => $newest->format(DATE_ATOM),
            'newest_late_seconds' => $late($newest),
        ]);
    }

    /** Read again: another planner may have inserted it since the check before the insert. */
    private function plannedMeanwhile(string $occurrenceKey): bool
    {
        return $this->runRepository->findByOccurrenceKey($occurrenceKey) !== null;
    }

    private function materializeRun(ScheduleDefinition $definition, ScheduledOccurrence $occurrence, ?OutputInterface $output): int
    {
        $occurrenceKey = $occurrence->occurrenceKey();

        if ($this->runRepository->findByOccurrenceKey($occurrenceKey) !== null) {
            return 0; // Idempotency: already planned
        }

        $overlapPolicy = OverlapPolicy::from($definition->getOverlapPolicy());
        $lockKey = $overlapPolicy !== OverlapPolicy::Allow ? $occurrence->lockKey() : null;

        $run = new ScheduledRun();
        $run->setSourceType(SourceType::Recurring->value);
        $run->setScheduleKey($definition->getScheduleKey());
        $run->setOccurrenceKey($occurrenceKey);
        $run->setJobClass($definition->getJobClass());
        $run->setTenantId($occurrence->tenantId);
        $run->setPool($definition->getPool());
        $run->setLockKey($lockKey);
        $run->setStatus(RunStatus::Pending->value);
        $run->setScheduledFor($occurrence->scheduledFor);
        $run->setAvailableAt($occurrence->scheduledFor);
        $run->setMaxAttempts($definition->getMaxAttempts());
        $run->setRetryBackoffSeconds($definition->getRetryBackoffSeconds());

        try {
            $this->runRepository->save($run);
        } catch (ConstraintViolationException $e) {
            // Another planner (another worker container) inserted this
            // occurrence after the check above: planned, not a failure. Any
            // other violation is still one.
            if ($this->plannedMeanwhile($occurrenceKey)) {
                return 0;
            }
            throw $e;
        }

        $output?->writeln(sprintf(
            '  Planned %s for <info>%s</info> at %s',
            $run->getId(),
            $definition->getScheduleKey(),
            $occurrence->scheduledFor->format('Y-m-d H:i:s'),
        ));

        return 1;
    }
}
