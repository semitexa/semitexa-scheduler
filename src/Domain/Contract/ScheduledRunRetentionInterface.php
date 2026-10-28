<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Contract;

interface ScheduledRunRetentionInterface
{
    /**
     * Delete up to $limit (at most 500) runs in a terminal status last
     * changed before $cutoff, with their history. Returns the runs deleted.
     */
    public function deleteFinishedBefore(\DateTimeImmutable $cutoff, int $limit): int;
}
