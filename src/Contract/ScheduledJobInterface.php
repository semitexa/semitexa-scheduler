<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Contract;

use Semitexa\Scheduler\Domain\Value\ScheduledJobContext;

interface ScheduledJobInterface
{
    public function handle(ScheduledJobContext $context): void;
}
