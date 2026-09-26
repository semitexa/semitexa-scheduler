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
 * A failed renewal now stops the job, so "0 affected rows" must mean "not the
 * owner" and nothing else. MySQL counts CHANGED rows, and the lease columns
 * are whole-second DATETIMEs: a second renewal inside the same second changes
 * nothing. The repositories confirm ownership before reporting a loss.
 */
final class LeaseRenewalOwnershipTest extends TestCase
{
    #[Test]
    public function a_same_second_lease_renewal_by_the_owner_still_succeeds(): void
    {
        $repo = $this->runRepository(ownerRowExists: true);

        self::assertTrue($repo->renewLease(Uuid7::generate(), 'w-1', 300));
    }

    #[Test]
    public function a_lease_renewal_by_a_worker_that_no_longer_owns_it_fails(): void
    {
        $repo = $this->runRepository(ownerRowExists: false);

        self::assertFalse($repo->renewLease(Uuid7::generate(), 'w-1', 300));
    }

    #[Test]
    public function a_same_second_lock_extension_by_the_owner_still_succeeds(): void
    {
        self::assertTrue($this->lockRepository(ownerRowExists: true)->extend('job:a', 'w-1', 120));
    }

    #[Test]
    public function a_lock_extension_by_a_worker_that_no_longer_holds_it_fails(): void
    {
        self::assertFalse($this->lockRepository(ownerRowExists: false)->extend('job:a', 'w-1', 120));
    }

    private function runRepository(bool $ownerRowExists): ScheduledRunRepository
    {
        return RepositoryHarness::repository(
            ScheduledRunRepository::class,
            RepositoryHarness::ormWithAdapter($this->unchangedUpdateAdapter($ownerRowExists)),
        );
    }

    private function lockRepository(bool $ownerRowExists): SchedulerLockRepository
    {
        return RepositoryHarness::repository(
            SchedulerLockRepository::class,
            RepositoryHarness::ormWithAdapter($this->unchangedUpdateAdapter($ownerRowExists)),
        );
    }

    /** Every UPDATE changes nothing; the SELECT answers whether the owner row exists. */
    private function unchangedUpdateAdapter(bool $ownerRowExists): RecordingAdapter
    {
        return new RecordingAdapter(static fn (string $sql): QueryResult => str_starts_with(ltrim($sql), 'SELECT')
            ? new QueryResult(rows: $ownerRowExists ? [['1' => 1]] : [])
            : new QueryResult(rowCount: 0));
    }
}
