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

    /** 64 slashes encode to 192 characters: with a 70-character key the row was refused by the 255 column. */
    #[Test]
    public function a_key_too_long_for_its_column_or_its_collation_is_stored_by_its_hash(): void
    {
        $saved = [];
        $runs = new class ($saved) implements ScheduledRunRepositoryInterface {
            /** @param list<ScheduledRun> $saved */
            public function __construct(private array &$saved) {}
            public function findById(string $id): ?ScheduledRun { return null; }
            public function findByOccurrenceKey(string $occurrenceKey): ?ScheduledRun { return null; }
            public function save(ScheduledRun $entity): void { $this->saved[] = $entity; }
            public function claimNextDue(string $pool, string $workerId, int $leaseTtlSeconds): ?string { return null; }
            public function renewLease(string $runId, string $workerId, int $leaseTtlSeconds): bool { return false; }
            public function finalizeIfOwned(ScheduledRun $run, ?string $expectedOwner): bool { return false; }
            public function reclaimExpiredLeases(\DateTimeImmutable $now): int { return 0; }
        };
        $scheduler = self::wired($runs);
        $tenant = str_repeat('/', 64);
        $key = str_repeat('k', 70);

        $scheduler->dispatchOnceAt('daily-report', \stdClass::class, new \DateTimeImmutable('+1 hour'), tenantId: 'museum');
        $scheduler->dispatchOnceAt($key, \stdClass::class, new \DateTimeImmutable('+1 hour'), tenantId: $tenant);
        // The column ignores case, accents and trailing spaces: these are kept
        // by their hash, which it compares byte for byte.
        $scheduler->dispatchOnceAt('Daily-report', \stdClass::class, new \DateTimeImmutable('+1 hour'), tenantId: 'museum');
        $scheduler->dispatchOnceAt('café', \stdClass::class, new \DateTimeImmutable('+1 hour'), tenantId: 'museum');
        $scheduler->dispatchOnceAt('daily-report ', \stdClass::class, new \DateTimeImmutable('+1 hour'), tenantId: 'museum');

        // No tenant, said as '' or as null, is one run with no tenant.
        $scheduler->dispatchOnceAt('nightly', \stdClass::class, new \DateTimeImmutable('+1 hour'), tenantId: '');
        $last = end($saved);
        self::assertInstanceOf(ScheduledRun::class, $last, 'the nightly run was saved');
        self::assertNull($last->getTenantId(), "'' is stored as no tenant");
        self::assertSame('once::nightly', $last->getOccurrenceKey());
        array_pop($saved);

        self::assertSame(
            [
                'once:museum:daily-report',
                'once#' . hash('sha256', 'once:' . str_repeat('%2F', 64) . ':' . $key),
                'once#' . hash('sha256', 'once:museum:Daily-report'),
                'once#' . hash('sha256', 'once:museum:café'),
                'once#' . hash('sha256', 'once:museum:daily-report '),
            ],
            array_map(static fn (ScheduledRun $run): ?string => $run->getOccurrenceKey(), $saved),
        );
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

        return self::wired($runs);
    }

    private static function wired(ScheduledRunRepositoryInterface $runs): SchedulerService
    {
        $factory = new DelayedRunFactory();
        (new \ReflectionProperty(DelayedRunFactory::class, 'runRepository'))->setValue($factory, $runs);
        $scheduler = new SchedulerService();
        (new \ReflectionProperty(SchedulerService::class, 'factory'))->setValue($scheduler, $factory);
        (new \ReflectionProperty(SchedulerService::class, 'runs'))->setValue($scheduler, $runs);

        return $scheduler;
    }
}
