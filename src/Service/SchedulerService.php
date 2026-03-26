<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Service;

use Semitexa\Core\Attributes\SatisfiesServiceContract;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Scheduler\Contract\SchedulerInterface;

#[SatisfiesServiceContract(of: SchedulerInterface::class)]
final class SchedulerService implements SchedulerInterface
{
    #[InjectAsReadonly]
    protected ?DelayedRunFactory $factory = null;

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
        if ($this->factory === null) {
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
        if ($this->factory === null) {
            throw new \RuntimeException('DelayedRunFactory is not available.');
        }

        $runAt = (new \DateTimeImmutable())->add($delay);
        return $this->dispatchAt($jobClass, $runAt, $payload, $pool, $tenantId, $lockKey, $maxAttempts, $retryBackoffSeconds);
    }

    private function defaultPool(): string
    {
        $value = getenv('SCHEDULER_DEFAULT_POOL');
        return $value !== false ? $value : 'default';
    }
}
