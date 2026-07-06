<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerLockRepository;
use Semitexa\Scheduler\Tests\Support\RecordingAdapter;
use Semitexa\Scheduler\Tests\Support\RepositoryHarness;

/**
 * acquire() detects contention by the INSERT's unique-key violation and then
 * tries to steal an expired lock. The old empty `catch (\Throwable)` treated
 * EVERY failure as contention — a deadlock / timeout / lost connection would
 * fall through to the steal UPDATE, weakening the distributed lock and risking
 * a double-run. Only a genuine duplicate-key violation may fall through; any
 * other fault must surface.
 */
final class SchedulerLockAcquireFaultTest extends TestCase
{
    #[Test]
    public function a_non_duplicate_db_fault_propagates_and_never_attempts_a_steal(): void
    {
        $adapter = new RecordingAdapter(function (string $sql): QueryResult {
            if (str_contains($sql, 'INSERT')) {
                throw new \RuntimeException('SQLSTATE[40001]: deadlock found when trying to get lock');
            }

            return new QueryResult(rowCount: 1);
        });
        $repo = RepositoryHarness::repository(
            SchedulerLockRepository::class,
            RepositoryHarness::ormWithAdapter($adapter),
        );

        try {
            $repo->acquire('job:a', Uuid7::generate(), 'w-1', 60);
            self::fail('a real DB fault must not be swallowed as contention');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('deadlock', $e->getMessage());
        }

        // Crucially: the steal UPDATE must NOT have run — only the failed INSERT
        // was attempted. Stealing here is what would weaken the lock.
        self::assertCount(1, $adapter->calls, 'no steal may be attempted after a non-duplicate fault');
        self::assertStringContainsString('INSERT', $adapter->calls[0]['sql']);
    }

    #[Test]
    public function a_duplicate_key_violation_falls_through_to_the_steal_update(): void
    {
        $adapter = new RecordingAdapter(function (string $sql): QueryResult {
            if (str_contains($sql, 'INSERT')) {
                throw new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', 23000);
            }

            // The lock is live, so the expired-only steal UPDATE affects 0 rows.
            return new QueryResult(rowCount: 0);
        });
        $repo = RepositoryHarness::repository(
            SchedulerLockRepository::class,
            RepositoryHarness::ormWithAdapter($adapter),
        );

        $acquired = $repo->acquire('job:a', Uuid7::generate(), 'w-2', 60);

        self::assertFalse($acquired, 'a live lock is not stolen');
        self::assertCount(2, $adapter->calls, 'contention falls through to the steal attempt');
        self::assertStringContainsString('INSERT', $adapter->calls[0]['sql']);
        self::assertStringContainsString('UPDATE', $adapter->calls[1]['sql']);
    }
}
