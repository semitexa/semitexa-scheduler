<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Enum;

enum OverlapPolicy: string
{
    case Allow = 'allow';
    case Skip = 'skip';
    case Delay = 'delay';
}
