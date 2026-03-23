<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Enum;

enum SourceType: string
{
    case Recurring = 'recurring';
    case Delayed = 'delayed';
}
