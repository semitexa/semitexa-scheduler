<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Enum;

enum RunStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Running = 'running';
    case RetryScheduled = 'retry_scheduled';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case SkippedOverlap = 'skipped_overlap';
    case MisfiredSkipped = 'misfire_skipped';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded,
            self::Failed,
            self::SkippedOverlap,
            self::MisfiredSkipped,
            self::Cancelled => true,
            default => false,
        };
    }

    public function isClaimable(): bool
    {
        return match ($this) {
            self::Pending,
            self::RetryScheduled => true,
            default => false,
        };
    }
}
