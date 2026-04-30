<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Domain\Enum\MisfirePolicy;

final class MisfirePolicyResolver
{
    /**
     * @param list<\DateTimeImmutable> $missedOccurrences sorted ascending (oldest first)
     * @return list<\DateTimeImmutable> occurrences to actually schedule
     */
    public function resolve(
        array $missedOccurrences,
        MisfirePolicy $policy,
        ?int $maxCatchUpRuns = null,
    ): array {
        if ($missedOccurrences === []) {
            return [];
        }

        return match ($policy) {
            MisfirePolicy::Skip => [],
            MisfirePolicy::RunOnce => [$missedOccurrences[count($missedOccurrences) - 1]],
            MisfirePolicy::CatchUp => array_slice(
                $missedOccurrences,
                max(0, count($missedOccurrences) - ($maxCatchUpRuns ?? PHP_INT_MAX)),
            ),
        };
    }
}
