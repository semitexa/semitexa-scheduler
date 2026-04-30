<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 1,
        public int $backoffSeconds = 0,
    ) {}

    public function shouldRetry(int $attemptCount): bool
    {
        return $attemptCount < $this->maxAttempts;
    }

    public function nextAvailableAt(int $attemptCount): \DateTimeImmutable
    {
        $delay = $this->backoffSeconds > 0
            ? $this->backoffSeconds * (2 ** max(0, $attemptCount - 1))
            : 0;
        return new \DateTimeImmutable("+{$delay} seconds");
    }
}
