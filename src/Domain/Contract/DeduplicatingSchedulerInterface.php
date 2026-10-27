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
     * scheduled already for the same $tenantId (whatever its status). Keys
     * are per tenant: two tenants may each schedule 'daily-report'.
     *
     * "Already" lasts as long as that run is kept: once finished it goes
     * with the run retention (SCHEDULER_RETENTION_DAYS, 30 days by default),
     * and its key is free again. For deduplicating work asked for twice at
     * about the same time, not for a once-ever guarantee.
     *
     * Stored with a prefix and the tenant's id in 255 characters: keep the
     * key well under 180.
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
