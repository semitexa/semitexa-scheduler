<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Enum\RunStatus;
use Semitexa\Scheduler\Domain\Enum\SourceType;

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
        $run->setSourceType(SourceType::Delayed->value);
        $run->setJobClass($jobClass);
        $run->setTenantId($tenantId);
        $run->setPool($pool);
        $run->setLockKey($lockKey);
        $run->setStatus(RunStatus::Pending->value);
        $run->setScheduledFor($scheduledFor);
        $run->setAvailableAt($availableAt);
        $run->setMaxAttempts($maxAttempts);
        $run->setRetryBackoffSeconds($retryBackoffSeconds);
        $run->setPayloadJson($payload !== [] ? json_encode($payload, JSON_THROW_ON_ERROR) : null);
        $this->runRepository->save($run);
        return $run->getId();
    }
}
