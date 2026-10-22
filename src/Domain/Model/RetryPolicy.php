<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

final readonly class RetryPolicy
{
    /**
     * Where doubling stops: a day, or the declared backoff when that is
     * longer. It had no ceiling: by the 20th attempt the wait was a year, and
     * past the 60th it overflowed into a float the date parser refuses, which
     * stopped the worker.
     */
    public const MAX_DELAY_SECONDS = 86400;

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
        $delay = 0;
        if ($this->backoffSeconds > 0) {
            // Doubled in a loop that stops at the cap, so the int never overflows.
            $cap = max($this->backoffSeconds, self::MAX_DELAY_SECONDS);
            $delay = $this->backoffSeconds;
            for ($i = 1; $i < $attemptCount && $delay < $cap; $i++) {
                $delay = min($delay * 2, $cap);
            }
        }
        return new \DateTimeImmutable("+{$delay} seconds");
    }
}
