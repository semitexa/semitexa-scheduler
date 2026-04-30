<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Contract;

use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;

interface ScheduledJobInterface
{
    public function handle(ScheduledJobContext $context): void;
}
