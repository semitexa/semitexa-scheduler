<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Contract;

/**
 * Optional beside {@see ScheduledRunRepositoryInterface}: answers for many
 * occurrence keys at once. The planner uses it when the run repository
 * implements it, and asks key by key otherwise.
 */
interface ScheduledRunOccurrenceLookupInterface
{
    /**
     * The given keys that already have a run, in any order.
     *
     * @param list<string> $occurrenceKeys
     * @return list<string>
     */
    public function findPlannedOccurrenceKeys(array $occurrenceKeys): array;
}
