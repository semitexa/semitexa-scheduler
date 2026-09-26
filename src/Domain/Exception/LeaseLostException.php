<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Exception;

use RuntimeException;

/**
 * Raised when a running job tries to renew its lease (or overlap lock) and the
 * row is no longer owned by this worker: the lease expired and was reclaimed,
 * or another worker took the lock. Continuing would run the job concurrently
 * with its new owner, so the renewal throws and the job stops at that
 * checkpoint instead.
 */
final class LeaseLostException extends RuntimeException
{
    public static function forRun(string $runId, string $workerId): self
    {
        return new self("Worker '{$workerId}' no longer holds the lease on run '{$runId}'.");
    }

    public static function forLock(string $lockKey, string $workerId): self
    {
        return new self("Worker '{$workerId}' no longer holds the overlap lock '{$lockKey}'.");
    }
}
