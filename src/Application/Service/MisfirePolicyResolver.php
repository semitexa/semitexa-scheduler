<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Domain\Enum\MisfirePolicy;

final class MisfirePolicyResolver
{
    /**
     * How late an occurrence may be planned and still count as on time. The
     * worker plans every 60 s, plus the time a planning run takes; an
     * occurrence older than this was missed (an outage, a stopped worker).
     */
    public const ON_TIME_SECONDS = 120;

    /**
     * @param list<\DateTimeImmutable> $occurrences the occurrences since the planning cursor, sorted ascending (oldest first)
     * @return list<\DateTimeImmutable> occurrences to actually schedule
     */
    public function resolve(
        array $occurrences,
        MisfirePolicy $policy,
        \DateTimeImmutable $now,
        ?int $maxCatchUpRuns = null,
    ): array {
        if ($occurrences === []) {
            return [];
        }

        return match ($policy) {
            // Skip drops the missed ones only: the occurrence due now still runs.
            MisfirePolicy::Skip => array_values(array_filter(
                $occurrences,
                static fn (\DateTimeImmutable $at): bool => $now->getTimestamp() - $at->getTimestamp() <= self::ON_TIME_SECONDS,
            )),
            MisfirePolicy::RunOnce => [$occurrences[count($occurrences) - 1]],
            MisfirePolicy::CatchUp => array_slice(
                $occurrences,
                max(0, count($occurrences) - ($maxCatchUpRuns ?? PHP_INT_MAX)),
            ),
        };
    }
}
