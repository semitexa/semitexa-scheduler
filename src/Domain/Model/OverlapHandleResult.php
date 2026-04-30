<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

final readonly class OverlapHandleResult
{
    public function __construct(
        public bool $proceed,
        public bool $lockAcquired,
    ) {}
}
