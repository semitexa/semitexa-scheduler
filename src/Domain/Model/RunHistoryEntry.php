<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

/**
 * One thing that happened to a scheduled run: it started, it changed status, a
 * worker claimed it, it failed with a message.
 *
 * The history is append-only and read when someone asks why a job behaved the
 * way it did, so what matters is the event and its before/after — not that
 * MySQL keeps run ids as 16 raw bytes and context as a JSON string. Those are
 * the row's business, and another database would spell them differently.
 */
final readonly class RunHistoryEntry
{
    /**
     * @param array<string, mixed>|null $context free-form detail for the event
     */
    public function __construct(
        private string $id,
        private string $runId,
        private string $eventType,
        private ?string $fromStatus = null,
        private ?string $toStatus = null,
        private ?string $workerId = null,
        private ?string $message = null,
        private ?array $context = null,
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getFromStatus(): ?string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): ?string
    {
        return $this->toStatus;
    }

    public function getWorkerId(): ?string
    {
        return $this->workerId;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /** @return array<string, mixed>|null */
    public function getContext(): ?array
    {
        return $this->context;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** True when the event moved the run from one status to another. */
    public function isTransition(): bool
    {
        return $this->toStatus !== null && $this->fromStatus !== $this->toStatus;
    }
}
