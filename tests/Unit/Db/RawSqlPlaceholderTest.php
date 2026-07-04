<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\ScheduledRunRepository;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerLockRepository;
use Semitexa\Scheduler\Tests\Support\RecordingAdapter;
use Semitexa\Scheduler\Tests\Support\RepositoryHarness;

/**
 * Regression for SQLSTATE[HY093]: the ORM runs with
 * ATTR_EMULATE_PREPARES=false, and native prepares reject a named
 * placeholder that appears more than once in a statement. The original
 * claim/lease SQL reused `:now` several times per statement and only died
 * at runtime — this drives every raw-SQL repository method through a
 * recording adapter and pins two invariants for each captured statement:
 * each placeholder occurs exactly once, and the bound parameter set
 * matches the placeholder set exactly.
 */
final class RawSqlPlaceholderTest extends TestCase
{
    #[Test]
    public function every_raw_statement_binds_each_placeholder_exactly_once(): void
    {
        $candidateId = Uuid7::toBytes(Uuid7::generate());

        $adapter = new RecordingAdapter(static function (string $sql) use ($candidateId): QueryResult {
            return str_starts_with(ltrim($sql), 'SELECT')
                ? new QueryResult(rows: [['id' => $candidateId]], rowCount: 1)
                : new QueryResult(rowCount: 1);
        });
        $orm = RepositoryHarness::ormWithAdapter($adapter);

        $runs = RepositoryHarness::repository(ScheduledRunRepository::class, $orm);
        $runs->claimNextDue('default', 'worker-1', 60);
        $runs->renewLease(Uuid7::generate(), 'worker-1', 60);
        $runs->reclaimExpiredLeases(new \DateTimeImmutable());

        $locks = RepositoryHarness::repository(SchedulerLockRepository::class, $orm);
        $locks->acquire('lock-a', Uuid7::generate(), 'worker-1', 60);
        $locks->extend('lock-a', 'worker-1', 60);
        $locks->release('lock-a', 'worker-1');
        $locks->deleteExpired(new \DateTimeImmutable());

        // Force the lock steal path too: INSERT throws -> UPDATE fallback.
        $stealAdapter = new RecordingAdapter(static function (string $sql): QueryResult {
            if (str_starts_with(ltrim($sql), 'INSERT')) {
                throw new \RuntimeException('duplicate key');
            }

            return new QueryResult(rowCount: 1);
        });
        $stealLocks = RepositoryHarness::repository(
            SchedulerLockRepository::class,
            RepositoryHarness::ormWithAdapter($stealAdapter),
        );
        $stealLocks->acquire('lock-a', Uuid7::generate(), 'worker-2', 60);

        $calls = array_merge($adapter->calls, $stealAdapter->calls);
        self::assertNotEmpty($calls);

        foreach ($calls as $call) {
            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $call['sql'], $matches);
            $placeholders = $matches[1];

            $duplicates = array_filter(array_count_values($placeholders), static fn (int $n): bool => $n > 1);
            self::assertSame(
                [],
                $duplicates,
                "Placeholder reused in statement (HY093 under native prepares):\n{$call['sql']}",
            );

            $unbound = array_diff($placeholders, array_keys($call['params']));
            $unused = array_diff(array_keys($call['params']), $placeholders);
            self::assertSame([], array_values($unbound), "Unbound placeholders in:\n{$call['sql']}");
            self::assertSame([], array_values($unused), "Params without placeholders in:\n{$call['sql']}");
        }
    }
}
