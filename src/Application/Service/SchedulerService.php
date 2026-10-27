<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Scheduler\Domain\Contract\DeduplicatingSchedulerInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\SchedulerInterface;

#[SatisfiesServiceContract(of: SchedulerInterface::class)]
final class SchedulerService implements DeduplicatingSchedulerInterface
{
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
                occurrenceKey: $occurrenceKey,
            );
        } catch (ConstraintViolationException $e) {
            // Only the occurrence key's own index means "already scheduled":
            // any 23xxx (a NOT NULL, a foreign key) read as it, and a
            // workflow's retry was reported scheduled and never ran.
            if (self::isOccurrenceDuplicate($e) || (isset($this->runs) && $this->runs->findByOccurrenceKey($occurrenceKey) !== null)) {
                return null;
            }

            throw $e;
        }
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
