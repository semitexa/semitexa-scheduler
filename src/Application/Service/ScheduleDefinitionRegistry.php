<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Exception\ConstraintViolationException;
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
            // A malformed attribute (a misspelled or mistyped argument) is
            // reported and passed over: it threw out of the sync, and no
            // schedule was planned on any pass.
            try {
                /** @var AsScheduledJob $attr */
                $attr = $attrs[0]->newInstance();
            } catch (\Error $e) {
                $problems[] = "Scheduled job '{$class}' has a malformed #[AsScheduledJob]: {$e->getMessage()}";
                continue;
            }

            // RunExecutor builds the job through the container, which builds
            // services only: a job that is not one would fail every run.
            if ($reflection->getAttributes(AsService::class) === []) {
                $problems[] = "Scheduled job '{$class}' ({$attr->key}) is not a service, so the scheduler cannot build it: mark it #[AsService].";
                continue;
            }

            try {
                $cron = $attr->cron();
            } catch (\InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
                continue;
            }

            try {
                $this->upsert($class, $attr, $cron);
            } catch (ConstraintViolationException) {
                // Another worker container inserted this schedule after the
                // lookup: write onto its row instead.
                $this->upsert($class, $attr, $cron);
            }
        }

        // A schedule whose job class was deleted kept being planned, and every
        // run failed. The planner now passes it over; the sync says so. It is
        // not disabled: a class missing for a moment (a deploy caught mid-sync)
        // would have switched the schedule off for good.
        //
        // A row whose key no class declares any more (the key was renamed,
        // the attribute removed, the module switched off) is passed over the
        // same way: it kept planning beside the renamed one, and the job ran
        // twice per occurrence. Not disabled either: after a rollback, or
        // while two images sync during a deploy, each would switch the
        // other's key off and the job would stop for good.
        // Discovery that found no job at all is more likely broken than right;
        // the planner then plans every row, and this says nothing either.
        $declaredKeys = $this->declaredKeys();
        $declared = array_flip($declaredKeys ?? []);
        foreach ($this->repository()->findAllEnabled() as $definition) {
            if (!class_exists($definition->getJobClass())) {
                $problems[] = "Schedule '{$definition->getScheduleKey()}' is not planned: its job class {$definition->getJobClass()} no longer exists.";
            } elseif ($declaredKeys !== null && !isset($declared[$definition->getScheduleKey()])) {
                $problems[] = "Schedule '{$definition->getScheduleKey()}' is not planned: no #[AsScheduledJob] declares it any more.";
            }
        }

        return $problems;
    }

    /**
     * Every key an #[AsScheduledJob] declares, including jobs the sync
     * refuses (not a service, a cron that does not parse): their rows are
     * kept as they were, not orphaned. Read from the raw arguments, so a
     * malformed attribute still declares its key.
     *
     * @return list<string>|null null when discovery found no #[AsScheduledJob]
     *         class at all; an empty list when it found jobs and none of them
     *         names a key (a misspelled `key:`), which is not the same thing
     */
    public function declaredKeys(): ?array
    {
        return $this->keys(buildableOnly: false);
    }

    /**
     * The declared keys the planner may plan: a job that is not a service is
     * left out. Its row was kept and planned, and every run of it failed when
     * the container could not build the job; the sync already says why.
     *
     * @return list<string>|null null as for declaredKeys()
     */
    public function plannableKeys(): ?array
    {
        return $this->keys(buildableOnly: true);
    }

    /** @return list<string>|null */
    private function keys(bool $buildableOnly): ?array
    {
        $classes = $this->classDiscovery()->findClassesWithAttribute(AsScheduledJob::class);
        if ($classes === []) {
            return null;
        }

        $keys = [];
        foreach ($classes as $class) {
            $reflection = new \ReflectionClass($class);
            if ($buildableOnly && $reflection->getAttributes(AsService::class) === []) {
                continue;
            }
            foreach ($reflection->getAttributes(AsScheduledJob::class) as $attribute) {
                // An argument that does not evaluate (an undefined constant)
                // throws here as in sync(), which reports it: thrown out of
                // here, it stopped scheduler:plan before any schedule planned.
                try {
                    $arguments = $attribute->getArguments();
                } catch (\Error) {
                    continue;
                }
                $key = $arguments['key'] ?? $arguments[0] ?? null;
                if (is_string($key)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    private function upsert(string $class, AsScheduledJob $attr, string $cron): void
    {
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
