<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Value;

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
    ) {}
}
