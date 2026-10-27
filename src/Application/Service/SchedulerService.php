<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Scheduler\Domain\Contract\DeduplicatingSchedulerInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\SchedulerInterface;

// Both contracts: a consumer that needs dispatchOnceAt() injects the
// deduplicating one, and the container resolved only the plain one.
#[SatisfiesServiceContract(of: SchedulerInterface::class)]
#[SatisfiesServiceContract(of: DeduplicatingSchedulerInterface::class)]
final class SchedulerService implements DeduplicatingSchedulerInterface
{
    /** scheduler_runs.occurrence_key's length */
    private const OCCURRENCE_KEY_LENGTH = 255;

    #[InjectAsReadonly]
    protected DelayedRunFactory $factory;

    #[InjectAsReadonly]
    protected ScheduledRunRepositoryInterface $runs;

    public function dispatchAt(
        string $jobClass,
        \DateTimeImmutable $runAt,
        array $payload = [],
        ?string $pool = null,
        ?string $tenantId = null,
        ?string $lockKey = null,
        int $maxAttempts = 1,
        int $retryBackoffSeconds = 0,
    ): string {
        if (!isset($this->factory)) {
            throw new \RuntimeException('DelayedRunFactory is not available.');
        }

        return $this->factory->create(
            jobClass: $jobClass,
            scheduledFor: $runAt,
            availableAt: $runAt,
            payload: $payload,
            pool: $pool ?? $this->defaultPool(),
            tenantId: $tenantId,
            lockKey: $lockKey,
            maxAttempts: $maxAttempts,
            retryBackoffSeconds: $retryBackoffSeconds,
        );
    }

    public function dispatchAfter(
        string $jobClass,
        \DateInterval $delay,
        array $payload = [],
        ?string $pool = null,
        ?string $tenantId = null,
        ?string $lockKey = null,
        int $maxAttempts = 1,
        int $retryBackoffSeconds = 0,
    ): string {
        $runAt = (new \DateTimeImmutable())->add($delay);
        return $this->dispatchAt($jobClass, $runAt, $payload, $pool, $tenantId, $lockKey, $maxAttempts, $retryBackoffSeconds);
    }

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
    ): ?string {
        if (!isset($this->factory)) {
            throw new \RuntimeException('DelayedRunFactory is not available.');
        }
        $storedKey = self::storedOccurrenceKey($occurrenceKey, $tenantId);
        // The unique occurrence_key index decides: of two at once, one row.
        try {
            return $this->factory->create(
                jobClass: $jobClass,
                scheduledFor: $runAt,
                availableAt: $runAt,
                payload: $payload,
                pool: $pool ?? $this->defaultPool(),
                tenantId: $tenantId,
                lockKey: $lockKey,
                maxAttempts: $maxAttempts,
                retryBackoffSeconds: $retryBackoffSeconds,
                occurrenceKey: $storedKey,
            );
        } catch (ConstraintViolationException $e) {
            // Only the occurrence key's own index means "already scheduled":
            // any 23xxx (a NOT NULL, a foreign key) read as it, and a
            // workflow's retry was reported scheduled and never ran.
            if (self::isOccurrenceDuplicate($e) || (isset($this->runs) && $this->runs->findByOccurrenceKey($storedKey) !== null)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * The key as stored: per tenant, and apart from the planner's own
     * `schedule:…` keys in the same unique column. Two tenants dispatching
     * 'daily-report' got one run between them, and the second tenant's job
     * was never scheduled. The tenant id is url-encoded, so a ':' in it cannot
     * make two tenants' keys the same; no tenant is the empty segment.
     *
     * The column holds 255: a longer key (a tenant id of slashes, encoded
     * three times over, and a fair key) was refused by strict MySQL and the
     * run never scheduled. Such a key is kept by its hash instead, after
     * 'once#', which no literal key starts with.
     */
    private static function storedOccurrenceKey(string $occurrenceKey, ?string $tenantId): string
    {
        $key = 'once:' . rawurlencode($tenantId ?? '') . ':' . $occurrenceKey;

        return strlen($key) <= self::OCCURRENCE_KEY_LENGTH ? $key : 'once#' . hash('sha256', $key);
    }

    /**
     * The driver's word for it, before the row is looked up: inside a MySQL
     * transaction a read can be older than the row that just won.
     */
    private static function isOccurrenceDuplicate(ConstraintViolationException $e): bool
    {
        $message = $e->getMessage();

        return ($e->driverCode === 1062 && str_contains($message, 'uniq_scheduler_run_occurrence'))
            || (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'occurrence_key'));
    }

    private function defaultPool(): string
    {
        $value = getenv('SCHEDULER_DEFAULT_POOL');
        return $value !== false ? $value : 'default';
    }
}
