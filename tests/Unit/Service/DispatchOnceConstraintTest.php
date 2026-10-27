<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Scheduler\Application\Service\DelayedRunFactory;
use Semitexa\Scheduler\Application\Service\SchedulerService;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;

/**
 * Every constraint error read as "already scheduled": a NOT NULL or a foreign
 * key refusing the row, and a workflow's retry was reported scheduled and
 * never ran.
 */
final class DispatchOnceConstraintTest extends TestCase
{
    #[Test]
    public function another_constraint_error_is_not_taken_for_a_duplicate(): void
    {
        $scheduler = self::scheduler(new ConstraintViolationException("SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'job_class' cannot be null", '23000', 1048));

        $this->expectException(ConstraintViolationException::class);
        $scheduler->dispatchOnceAt('k1', \stdClass::class, new \DateTimeImmutable('+1 hour'));
    }

    #[Test]
    public function the_occurrence_key_s_own_index_is(): void
    {
        $mysql = self::scheduler(new ConstraintViolationException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'k1' for key 'scheduler_runs.uniq_scheduler_run_occurrence'", '23000', 1062));
        $sqlite = self::scheduler(new ConstraintViolationException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: scheduler_runs.occurrence_key', '23000', 19));

        self::assertNull($mysql->dispatchOnceAt('k1', \stdClass::class, new \DateTimeImmutable('+1 hour')));
        self::assertNull($sqlite->dispatchOnceAt('k1', \stdClass::class, new \DateTimeImmutable('+1 hour')));
    }

    private static function scheduler(ConstraintViolationException $onSave): SchedulerService
    {
        $runs = new class ($onSave) implements ScheduledRunRepositoryInterface {
            public function __construct(private ConstraintViolationException $onSave) {}
            public function findById(string $id): ?ScheduledRun { return null; }
            public function findByOccurrenceKey(string $occurrenceKey): ?ScheduledRun { return null; }
            public function save(ScheduledRun $entity): void { throw $this->onSave; }
            public function claimNextDue(string $pool, string $workerId, int $leaseTtlSeconds): ?string { return null; }
            public function renewLease(string $runId, string $workerId, int $leaseTtlSeconds): bool { return false; }
            public function finalizeIfOwned(ScheduledRun $run, ?string $expectedOwner): bool { return false; }
            public function reclaimExpiredLeases(\DateTimeImmutable $now): int { return 0; }
        };
        $factory = new DelayedRunFactory();
        (new \ReflectionProperty(DelayedRunFactory::class, 'runRepository'))->setValue($factory, $runs);
        $scheduler = new SchedulerService();
        (new \ReflectionProperty(SchedulerService::class, 'factory'))->setValue($scheduler, $factory);
        (new \ReflectionProperty(SchedulerService::class, 'runs'))->setValue($scheduler, $runs);

        return $scheduler;
    }
}
