<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

final readonly class ScheduledJobContext
{
    public function __construct(
        public string $runId,
        public string $jobClass,
        public string $pool,
        public ?string $tenantId = null,
        public ?string $scheduleKey = null,
        public string $sourceType = 'delayed',
        public int $attemptNumber = 1,
        public array $payload = [],
        private ?\Closure $renewLease = null,
    ) {}

    /**
     * Keep this run's lease (and overlap lock) alive. The worker does not renew
     * it on its own, so a job that can outlast the lease TTL must call this at
     * natural checkpoints — otherwise another worker reclaims the "expired"
     * lease and runs the same job a second time, concurrently.
     */
    public function renewLease(): void
    {
        if ($this->renewLease !== null) {
            ($this->renewLease)();
        }
    }
}
