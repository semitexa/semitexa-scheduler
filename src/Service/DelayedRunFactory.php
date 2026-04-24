<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Scheduler\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Enum\RunStatus;
use Semitexa\Scheduler\Enum\SourceType;

#[AsService]
final class DelayedRunFactory
{
    #[\Semitexa\Core\Attribute\InjectAsReadonly]
    protected ScheduledRunRepositoryInterface $runRepository;

    public function create(
        string $jobClass,
        \DateTimeImmutable $scheduledFor,
        \DateTimeImmutable $availableAt,
        array $payload = [],
        string $pool = 'default',
        ?string $tenantId = null,
        ?string $lockKey = null,
        int $maxAttempts = 1,
        int $retryBackoffSeconds = 0,
    ): string {
        if (!isset($this->runRepository)) {
            throw new \RuntimeException('ScheduledRunRepositoryInterface is not available.');
        }

        $run = new ScheduledRun();
        $run->sourceType = SourceType::Delayed->value;
        $run->jobClass = $jobClass;
        $run->tenantId = $tenantId;
        $run->pool = $pool;
        $run->lockKey = $lockKey;
        $run->status = RunStatus::Pending->value;
        $run->scheduledFor = $scheduledFor;
        $run->availableAt = $availableAt;
        $run->maxAttempts = $maxAttempts;
        $run->retryBackoffSeconds = $retryBackoffSeconds;
        $run->payloadJson = $payload !== [] ? json_encode($payload, JSON_THROW_ON_ERROR) : null;
        $this->runRepository->save($run);
        return $run->id;
    }
}
