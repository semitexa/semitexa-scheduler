<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Scheduler\Attribute\AsScheduledJob;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
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
     *
     * Every field is rewritten from the attribute, so the attribute — including
     * whatever its `env::VAR::default` cron resolves to on this install — is the
     * only source of truth. Editing a row by hand does not survive this.
     *
     * @return list<string> schedules that could NOT be synced, one message each.
     *                      A job whose cron does not parse is left alone rather
     *                      than taken as read: the rest of the schedules still
     *                      sync, and the planner still runs this tick.
     */
    public function sync(): array
    {
        $problems = [];

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

            try {
                $cron = $attr->cron();
            } catch (\InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
                continue;
            }

            $existing = $this->repository()->findByKey($attr->key);
            $definition = $existing ?? new ScheduleDefinition();
            $definition->setScheduleKey($attr->key);
            $definition->setJobClass($class);
            $definition->setCronExpression($cron);
            $definition->setPool($attr->pool);
            $definition->setOverlapPolicy($attr->overlapPolicy);
            $definition->setMisfirePolicy($attr->misfirePolicy);
            $definition->setTenantMode($attr->tenantMode);
            $definition->setMaxAttempts($attr->maxAttempts);
            $definition->setRetryBackoffSeconds($attr->retryBackoffSeconds);
            $definition->setMaxCatchUpRuns($attr->maxCatchUpRuns);
            $this->repository()->save($definition);
        }

        return $problems;
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
