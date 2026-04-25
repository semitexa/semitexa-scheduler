<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Scheduler\Attribute\AsScheduledJob;
use Semitexa\Scheduler\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;

#[AsService]
final class ScheduleDefinitionRegistry
{
    #[InjectAsReadonly]
    protected ScheduleDefinitionRepositoryInterface $repository;

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    /**
     * Discover all classes tagged with #[AsScheduledJob] and upsert them into the DB.
     */
    public function sync(): void
    {
        /** @var list<class-string> $classes */
        $classes = $this->classDiscovery()->findClassesWithAttribute(AsScheduledJob::class);

        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            $attrs = $reflection->getAttributes(AsScheduledJob::class);
            if ($attrs === []) {
                continue;
            }
            /** @var AsScheduledJob $attr */
            $attr = $attrs[0]->newInstance();
            $existing = $this->repository()->findByKey($attr->key);
            $definition = $existing ?? new ScheduleDefinition();
            $definition->scheduleKey = $attr->key;
            $definition->jobClass = $class;
            $definition->cronExpression = $attr->cronExpression;
            $definition->pool = $attr->pool;
            $definition->overlapPolicy = $attr->overlapPolicy;
            $definition->misfirePolicy = $attr->misfirePolicy;
            $definition->tenantMode = $attr->tenantMode;
            $definition->maxAttempts = $attr->maxAttempts;
            $definition->retryBackoffSeconds = $attr->retryBackoffSeconds;
            $definition->maxCatchUpRuns = $attr->maxCatchUpRuns;
            $this->repository()->save($definition);
        }
    }

    /** @return list<ScheduleDefinition> */
    public function all(): array
    {
        return $this->repository()->findAllEnabled();
    }

    private function repository(): ScheduleDefinitionRepositoryInterface
    {
        return $this->repository ?? throw new \RuntimeException('ScheduleDefinitionRepositoryInterface not injected into ' . self::class . '.');
    }

    private function classDiscovery(): ClassDiscovery
    {
        return $this->classDiscovery ??= new ClassDiscovery();
    }
}
