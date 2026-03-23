<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Configuration;

final readonly class SchedulerConfig
{
    public string $defaultPool;
    public int $pollIntervalSeconds;
    public int $leaseTtlSeconds;
    public int $lockTtlSeconds;
    public int $heartbeatIntervalSeconds;
    public int $defaultMaxAttempts;
    public int $defaultRetryBackoffSeconds;
    public int $runRetentionDays;

    public function __construct(
        string $defaultPool = 'default',
        int $pollIntervalSeconds = 5,
        int $leaseTtlSeconds = 300,
        int $lockTtlSeconds = 300,
        int $defaultMaxAttempts = 3,
        int $defaultRetryBackoffSeconds = 60,
        int $runRetentionDays = 30,
    ) {
        $this->defaultPool = $defaultPool;
        $this->pollIntervalSeconds = $pollIntervalSeconds;
        $this->leaseTtlSeconds = $leaseTtlSeconds;
        $this->lockTtlSeconds = $lockTtlSeconds;
        $this->heartbeatIntervalSeconds = (int) ($leaseTtlSeconds / 3);
        $this->defaultMaxAttempts = $defaultMaxAttempts;
        $this->defaultRetryBackoffSeconds = $defaultRetryBackoffSeconds;
        $this->runRetentionDays = $runRetentionDays;
    }

    public static function create(): self
    {
        $env = static function (string $key, int $default): int {
            $value = getenv($key);
            return $value !== false ? (int) $value : $default;
        };

        $envStr = static function (string $key, string $default): string {
            $value = getenv($key);
            return $value !== false ? $value : $default;
        };

        return new self(
            defaultPool: $envStr('SCHEDULER_DEFAULT_POOL', 'default'),
            pollIntervalSeconds: $env('SCHEDULER_POLL_INTERVAL', 5),
            leaseTtlSeconds: $env('SCHEDULER_LEASE_TTL', 300),
            lockTtlSeconds: $env('SCHEDULER_LOCK_TTL', 300),
            defaultMaxAttempts: $env('SCHEDULER_MAX_ATTEMPTS', 3),
            defaultRetryBackoffSeconds: $env('SCHEDULER_RETRY_BACKOFF', 60),
            runRetentionDays: $env('SCHEDULER_RETENTION_DAYS', 30),
        );
    }
}
