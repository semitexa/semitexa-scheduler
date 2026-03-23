<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Value;

final readonly class ScheduledOccurrence
{
    public function __construct(
        public string $scheduleKey,
        public \DateTimeImmutable $scheduledFor,
        public ?string $tenantId = null,
    ) {}

    public function occurrenceKey(): string
    {
        $iso = $this->scheduledFor->format('Y-m-d\TH:i:s\Z');
        if ($this->tenantId !== null) {
            return "schedule:{$this->scheduleKey}:tenant:{$this->tenantId}:at:{$iso}";
        }
        return "schedule:{$this->scheduleKey}:at:{$iso}";
    }

    public function lockKey(): string
    {
        if ($this->tenantId !== null) {
            return "scheduler:{$this->scheduleKey}:tenant:{$this->tenantId}";
        }
        return "scheduler:{$this->scheduleKey}";
    }
}
