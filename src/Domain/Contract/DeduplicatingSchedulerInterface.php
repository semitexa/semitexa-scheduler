<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Contract;

/**
 * A scheduler that schedules a job once per key: a second dispatch with a key
 * already scheduled schedules nothing. For work two runs can ask for at once —
 * a reclaimed run and the slow original both scheduling the next retry, which
 * forked one chain into two.
 */
interface DeduplicatingSchedulerInterface extends SchedulerInterface
{
    /**
     * The id of the run scheduled, or null when one with $occurrenceKey is
     * scheduled already (whatever its status). At most 255 characters.
     *
     * @param array<mixed> $payload
     */
    public function dispatchOnceAt(
        string $occurrenceKey,
        string $jobClass,
        \DateTimeImmutable $runAt,
        array $payload = [],
        ?string $pool = null,
        ?string $tenantId = null,
        ?string $lockKey = null,
        int $maxAttempts = 1,
        int $retryBackoffSeconds = 0,
    ): ?string;
}
