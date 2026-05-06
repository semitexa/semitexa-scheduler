<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Enum;

enum MisfirePolicy: string
{
    case Skip = 'skip';
    case RunOnce = 'run_once';
    case CatchUp = 'catch_up';
}
