<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Enum;

enum MisfirePolicy: string
{
    case Skip = 'skip';
    case RunOnce = 'run_once';
    case CatchUp = 'catch_up';
}
