<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsScheduledJob
{
    public function __construct(
        public string $key,
        public string $cronExpression,
        public string $pool = 'default',
        public string $overlapPolicy = 'skip',
        public string $misfirePolicy = 'run_once',
        public string $tenantMode = 'global',
        public int $maxAttempts = 1,
        public int $retryBackoffSeconds = 0,
        public ?int $maxCatchUpRuns = null,
    ) {}
}
