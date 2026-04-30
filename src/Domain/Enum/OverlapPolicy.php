<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Enum;

enum OverlapPolicy: string
{
    case Allow = 'allow';
    case Skip = 'skip';
    case Delay = 'delay';
}
