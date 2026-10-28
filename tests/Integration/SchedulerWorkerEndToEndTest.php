<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\ScheduleDefinitionRepository;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\ScheduledRunRepository;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerLockRepository;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Application\Service\DelayedRunFactory;
use Semitexa\Scheduler\Application\Service\OverlapPolicyHandler;
use Semitexa\Scheduler\Application\Service\RetryScheduler;
use Semitexa\Scheduler\Application\Service\RunExecutor;
use Semitexa\Scheduler\Application\Service\RunLeaseManager;
use Semitexa\Scheduler\Application\Service\SchedulerLockManager;
use Semitexa\Scheduler\Application\Service\SchedulerService;
use Semitexa\Scheduler\Application\Service\SchedulerWorker;
use Semitexa\Scheduler\Configuration\SchedulerConfig;
use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\SchedulerLockRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\SchedulerLock;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Tenancy\Context\CoroutineContextStore;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The scheduler worker loop end to end — SchedulerService::dispatchAt() writes
 * the run, SchedulerWorker::run() reclaims, claims, executes and finalizes it —
 * through the real services and repositories against the orm:sync'ed MySQL
 * scheduler tables. Nothing below the worker is stubbed: the only test seams
 * are the job (planted in the sealed container's readonly store, the way the
 * queue-worker tests plant listeners), a repository decorator that stops the
 * loop once its pool is drained, and, for the race, an adapter decorator that
 * opens the window between the claim's SELECT and its UPDATE.
 *
 * Every row lives in a pool of its own (random suffix), so the loop can only
 * claim this test's runs. reclaimExpiredLeases() and the lock cleanup are
 * table-wide by design and run as they do in production. Skipped without MySQL.
 */
final class SchedulerWorkerEndToEndTest extends TestCase
{
    private OrmManager $orm;
    private ScheduledRunRepository $runs;
    private SchedulerRunHistoryRepository $history;
    private SchedulerLockRepository $locks;
    private ScheduleDefinitionRepository $definitions;
    private SchedulerService $scheduler;
    private BufferedOutput $output;
    private string $pool;
    private string $tenant;
    private ?string $lastDispatchReturned = null;
    private string $logText = '';

    protected function setUp(): void
    {
        try {
            $this->orm = new OrmManager();
            $why = $this->orm->getDriver() !== 'mysql' ? 'not MySQL' : null;
            foreach (['scheduler_runs', 'scheduler_run_history', 'scheduler_locks', 'scheduler_schedule_definitions'] as $table) {
                $this->orm->getAdapter()->query("SELECT 1 FROM {$table} LIMIT 0");
            }
        } catch (\Throwable $e) {
            $why = 'MySQL or the synced scheduler tables unavailable: ' . $e->getMessage();
        }
        if ($why !== null) {
            if (isset($this->orm)) {
                $this->orm->shutdown();
            }
            self::markTestSkipped($why);
        }

        // RunExecutor resolves the job class from the container, which is
        // sealed after boot; the job goes into its readonly store. Booting it
        // here also pins the process to UTC before any timestamp is taken.
        self::store()->readonly[SchedulerEndToEndJob::class] = new SchedulerEndToEndJob();
        SchedulerEndToEndJob::$calls = [];
        SchedulerEndToEndJob::$onHandle = null;

        $this->runs = self::withOrm(new ScheduledRunRepository(), $this->orm);
        $this->history = self::withOrm(new SchedulerRunHistoryRepository(), $this->orm);
        $this->locks = self::withOrm(new SchedulerLockRepository(), $this->orm);
        $this->definitions = self::withOrm(new ScheduleDefinitionRepository(), $this->orm);

        $factory = new DelayedRunFactory();
        (new \ReflectionProperty(DelayedRunFactory::class, 'runRepository'))->setValue($factory, $this->runs);
        $this->scheduler = new SchedulerService();
        (new \ReflectionProperty(SchedulerService::class, 'factory'))->setValue($this->scheduler, $factory);

        $suffix = bin2hex(random_bytes(6));
        $this->pool = 'e2e-' . $suffix;
        $this->tenant = 'e2e-tenant-' . $suffix;
        $this->output = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        if (!isset($this->runs)) {
            return;
        }
        unset(self::store()->readonly[SchedulerEndToEndJob::class]);
        SchedulerEndToEndJob::$calls = [];
        SchedulerEndToEndJob::$onHandle = null;

        $db = $this->orm->getAdapter();
        $db->execute(
            'DELETE FROM scheduler_run_history WHERE run_id IN (SELECT id FROM scheduler_runs WHERE pool = :pool)',
            ['pool' => $this->pool],
        );
        $db->execute('DELETE FROM scheduler_runs WHERE pool = :pool', ['pool' => $this->pool]);
        $db->execute('DELETE FROM scheduler_locks WHERE lock_key = :key', ['key' => $this->pool . ':lock']);
        $this->orm->shutdown();
    }

    /**
     * REGRESSION: dispatchAt() returned ''. ScheduledRunRepository::save()
     * copied the persisted run back with get_object_vars() from outside the
     * class, which sees none of ScheduledRun's private properties, so the id
     * the insert made never reached the caller (scheduler:run-now printed an
     * empty id; --inline threw on Uuid7::toBytes('')). Fixed by copying through
     * reflection, here and in ScheduleDefinitionRepository.
     */
    #[Test]
    public function dispatching_a_run_reports_the_id_of_the_run_it_created(): void
    {
        $rowId = $this->dispatch();

        self::assertSame($rowId, $this->lastDispatchReturned, 'dispatchAt() returns the id of the run it wrote');
    }

    #[Test]
    public function a_due_run_is_claimed_executed_once_and_finalized(): void
    {
        $ambientBefore = CoroutineContextStore::get();
        $runId = $this->dispatch(payload: ['order' => 42], tenantId: $this->tenant);

        [$worker, $loop] = $this->worker();
        $worker->run();

        self::assertFalse($loop->deadlineHit, 'the loop never drained: ' . $this->log());
        self::assertSame($runId, $loop->claims[0] ?? null, 'the due run is the one the loop claimed');

        $calls = SchedulerEndToEndJob::$calls;
        self::assertCount(1, $calls, 'executed exactly once');
        self::assertSame($runId, $calls[0]['runId']);
        self::assertSame(1, $calls[0]['attempt']);
        self::assertSame(['order' => 42], $calls[0]['payload']);
        self::assertSame($this->pool, $calls[0]['pool']);
        self::assertSame($this->tenant, $calls[0]['tenant']);
        self::assertSame($this->tenant, $calls[0]['ambientTenant'], 'the job runs inside its tenant context');
        self::assertSame($ambientBefore, CoroutineContextStore::get(), 'the previous tenant context is restored afterwards');

        $row = $this->row($runId);
        self::assertSame('succeeded', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertNotNull($row['started_at']);
        self::assertNotNull($row['finished_at']);
        self::assertGreaterThanOrEqual(self::at($row['started_at']), self::at($row['finished_at']));
        self::assertNull($row['lease_owner'], 'lease released');
        self::assertNull($row['lease_expires_at'], 'lease released');
        self::assertNull($row['last_error']);
        self::assertSame(['running', 'succeeded'], array_column($this->historyOf($runId), 'event_type'));

        // A second worker over the same pool finds nothing left to do.
        [$again, $againLoop] = $this->worker();
        $again->run();
        self::assertSame([null], $againLoop->claims);
        self::assertCount(1, SchedulerEndToEndJob::$calls, 'a finalized run is never executed again');
    }

    #[Test]
    public function a_job_that_throws_is_retried_until_its_attempts_run_out_and_then_marked_failed(): void
    {
        $runId = $this->dispatch(payload: ['fail' => 'boom'], maxAttempts: 2, retryBackoffSeconds: 0);

        [$worker, $loop] = $this->worker();
        $worker->run();

        self::assertFalse($loop->deadlineHit, 'the loop never drained: ' . $this->log());
        self::assertSame([1, 2], array_column(SchedulerEndToEndJob::$calls, 'attempt'), 'one retry, then terminal');
        // A retry with no backoff is due "now", which DATETIME may round up by
        // a second: an empty poll before it comes due is not a miss.
        self::assertSame([$runId, $runId], array_values(array_filter($loop->claims)), 'the retry is claimed again once it is due');
        self::assertNull($loop->claims[array_key_last($loop->claims)], 'and then nothing is left');

        $row = $this->row($runId);
        self::assertSame('failed', $row['status']);
        self::assertSame(2, (int) $row['attempt_count']);
        self::assertSame('boom #2', $row['last_error']);
        self::assertNotNull($row['finished_at']);
        self::assertNull($row['lease_owner']);
        self::assertNull($row['lease_expires_at']);
        self::assertSame(
            ['running', 'retry_scheduled', 'running', 'failed'],
            array_column($this->historyOf($runId), 'event_type'),
        );
        self::assertStringContainsString('failed permanently: boom #2', $this->log());
    }

    #[Test]
    public function a_job_that_throws_waits_out_its_backoff_before_the_next_attempt(): void
    {
        $runId = $this->dispatch(payload: ['fail' => 'flaky'], maxAttempts: 3, retryBackoffSeconds: 60);
        $before = new \DateTimeImmutable();

        [$worker, $loop] = $this->worker();
        $worker->run();

        self::assertFalse($loop->deadlineHit, 'the loop never drained: ' . $this->log());
        self::assertCount(1, SchedulerEndToEndJob::$calls, 'the retry is not due yet');
        self::assertSame([$runId, null], $loop->claims);

        $row = $this->row($runId);
        self::assertSame('retry_scheduled', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertSame('flaky #1', $row['last_error']);
        self::assertNull($row['finished_at'], 'not terminal');
        self::assertNull($row['lease_owner']);
        self::assertNull($row['lease_expires_at']);
        // First retry: backoff * 2^(attempt-1) = 60 s; one second of slack for DATETIME rounding.
        self::assertGreaterThanOrEqual($before->modify('+59 seconds'), self::at($row['available_at']));
        self::assertLessThanOrEqual((new \DateTimeImmutable())->modify('+61 seconds'), self::at($row['available_at']));
        self::assertSame(['running', 'retry_scheduled'], array_column($this->historyOf($runId), 'event_type'));
    }

    /**
     * A message in Latin-1 (a driver's, an upstream body) is not UTF-8, and
     * strict MySQL refused it in last_error: the write threw, the worker
     * stopped, and the run stayed `running` until its lease ran out.
     */
    #[Test]
    public function an_error_that_is_not_utf8_is_stored_and_the_worker_goes_on(): void
    {
        $runId = $this->dispatch(payload: ['latin1' => true]);

        [$worker, $loop] = $this->worker();
        $worker->run();

        self::assertFalse($loop->deadlineHit, 'the loop never drained: ' . $this->log());
        $row = $this->row($runId);
        self::assertSame('failed', $row['status']);
        self::assertSame("caf\u{FFFD} down #1", $row['last_error']);
    }

    /**
     * A write the database refused after the job ran stopped the worker; the
     * supervisor's restart claimed the same run and stopped again. The worker
     * now says so and goes on; the run keeps its lease for the reclaim.
     */
    #[Test]
    public function a_failed_state_write_is_logged_and_the_worker_goes_on(): void
    {
        $runId = $this->dispatch();

        [$worker, $loop] = $this->worker(new FinalizeRefusedRunRepository($this->runs));
        $worker->run();

        self::assertFalse($loop->deadlineHit, 'the loop never drained: ' . $this->log());
        self::assertCount(1, SchedulerEndToEndJob::$calls);
        self::assertStringContainsString("Run '{$runId}': writing its state or history failed: RuntimeException: refused", $this->log());
        self::assertSame('running', $this->row($runId)['status'], 'left to the lease reclaim');
    }

    /** dispatchAt() takes any class name: one that is not a job must not have its handle() called. */
    #[Test]
    public function a_class_that_is_not_a_scheduled_job_is_not_run(): void
    {
        // The container's store and the static flag are process-wide: what
        // was there before is put back, not cleared.
        $store = self::store();
        $hadEntry = array_key_exists(SchedulerNotAJob::class, $store->readonly);
        $previousEntry = $store->readonly[SchedulerNotAJob::class] ?? null;
        $previousCalled = SchedulerNotAJob::$called;
        $store->readonly[SchedulerNotAJob::class] = new SchedulerNotAJob();
        SchedulerNotAJob::$called = false;
        try {
            $runId = $this->dispatch(jobClass: SchedulerNotAJob::class);

            [$worker, $loop] = $this->worker();
            $worker->run();

            self::assertFalse($loop->deadlineHit, 'the loop never drained: ' . $this->log());
            self::assertFalse(SchedulerNotAJob::$called);
            $row = $this->row($runId);
            self::assertSame('failed', $row['status']);
            self::assertStringContainsString('is not a scheduled job', (string) $row['last_error']);
        } finally {
            if ($hadEntry) {
                $store->readonly[SchedulerNotAJob::class] = $previousEntry;
            } else {
                unset($store->readonly[SchedulerNotAJob::class]);
            }
            SchedulerNotAJob::$called = $previousCalled;
        }
    }

    /** docker stop lands mid-pass (sweeping, reclaiming): the pass must not go on to claim and run a whole new job. */
    #[Test]
    public function a_stop_asked_during_a_pass_claims_nothing_new(): void
    {
        $runId = $this->dispatch(payload: ['order' => 7], tenantId: $this->tenant);
        [$worker, $loop] = $this->worker();
        $loop->stopDuringPass = true;

        $worker->run();

        self::assertSame([], $loop->claims, 'nothing claimed after the stop');
        self::assertCount(0, SchedulerEndToEndJob::$calls);
        self::assertSame('pending', $this->row($runId)['status'], 'left for the next worker');
        self::assertStringContainsString('Scheduler worker stopped', $this->log());
    }

    /** SIGTERM while a job runs: that run is finished and recorded, and the next one is left for another worker. */
    #[Test]
    public function a_stop_asked_while_a_run_executes_finishes_that_run_and_claims_nothing_else(): void
    {
        $firstId = $this->dispatch(payload: ['order' => 1], tenantId: $this->tenant);
        $secondId = $this->dispatch(payload: ['order' => 2], tenantId: $this->tenant);
        [$worker, $loop] = $this->worker();
        SchedulerEndToEndJob::$onHandle = static function () use ($worker): void {
            $worker->stop();
        };

        $worker->run();

        self::assertCount(1, $loop->claims, 'one claim, then the loop ended: ' . $this->log());
        $finishedId = $loop->claims[0];
        self::assertContains($finishedId, [$firstId, $secondId]);
        $untouchedId = $finishedId === $firstId ? $secondId : $firstId;
        self::assertSame([$finishedId], array_column(SchedulerEndToEndJob::$calls, 'runId'));
        $finished = $this->row($finishedId);
        self::assertSame('succeeded', $finished['status']);
        self::assertNotNull($finished['finished_at']);
        self::assertNull($finished['lease_owner']);
        self::assertSame(['running', 'succeeded'], array_column($this->historyOf($finishedId), 'event_type'));
        $untouched = $this->row($untouchedId);
        self::assertSame('pending', $untouched['status'], 'left for the next worker');
        self::assertSame(0, (int) $untouched['attempt_count']);
        self::assertStringContainsString('Scheduler worker stopped', $this->log());
    }

    #[Test]
    public function two_workers_racing_for_the_same_due_run_execute_it_once(): void
    {
        $runId = $this->dispatch();

        // Worker B: the ordinary stack.
        [$workerB, $loopB] = $this->worker();

        // Worker A: the same stack, but its run repository talks through an
        // adapter that runs worker B to completion in the exact window between
        // A's candidate SELECT and A's claiming UPDATE.
        $interleave = new InterleavingAdapter($this->orm->getAdapter(), static fn () => $workerB->run());
        $ormA = new OrmManager();
        (new \ReflectionProperty(OrmManager::class, 'adapter'))->setValue($ormA, $interleave);
        [$workerA, $loopA] = $this->worker(self::withOrm(new ScheduledRunRepository(), $ormA));

        $workerA->run();

        self::assertTrue($interleave->fired, 'worker A reached its claiming UPDATE with the candidate in hand');
        self::assertFalse($loopA->deadlineHit || $loopB->deadlineHit, 'a loop never drained: ' . $this->log());
        self::assertSame([$runId, null], $loopB->claims, 'B won the claim');
        self::assertSame(0, $interleave->claimRowCount, "A's UPDATE matched nothing: the status guard refused it");
        self::assertSame([null], $loopA->claims, 'A lost the race and claimed nothing');

        self::assertCount(1, SchedulerEndToEndJob::$calls, 'executed exactly once');
        $row = $this->row($runId);
        self::assertSame('succeeded', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertNull($row['lease_owner']);

        $history = $this->historyOf($runId);
        self::assertSame(['running', 'succeeded'], array_column($history, 'event_type'));
        self::assertCount(1, array_unique(array_column($history, 'worker_id')), 'one worker owned the whole run');
    }

    #[Test]
    public function an_expired_lease_is_reclaimed_and_the_run_completes_on_another_worker(): void
    {
        $runId = $this->dispatch(maxAttempts: 3);
        $deadWorker = 'w-dead-' . bin2hex(random_bytes(4));
        $now = new \DateTimeImmutable();
        // A worker claimed it, started attempt 1, and died: its lease ran out a minute ago.
        $this->orm->getAdapter()->execute(
            "UPDATE scheduler_runs
             SET status = 'running', lease_owner = :owner, lease_expires_at = :expired,
                 attempt_count = 1, started_at = :started
             WHERE id = :id",
            [
                'owner' => $deadWorker,
                'expired' => $now->modify('-1 minute')->format('Y-m-d H:i:s'),
                'started' => $now->modify('-6 minutes')->format('Y-m-d H:i:s'),
                'id' => Uuid7::toBytes($runId),
            ],
        );

        [$worker, $loop] = $this->worker();
        $worker->run();
        $log = $this->log();

        self::assertFalse($loop->deadlineHit, 'the loop never drained: ' . $log);
        self::assertStringContainsString('expired lease(s)', $log, 'the loop reclaimed before claiming');
        self::assertSame([$runId, null], $loop->claims);
        self::assertSame([2], array_column(SchedulerEndToEndJob::$calls, 'attempt'), 'the dead attempt still counts');

        $row = $this->row($runId);
        self::assertSame('succeeded', $row['status']);
        self::assertSame(2, (int) $row['attempt_count']);
        self::assertNull($row['lease_owner']);
        self::assertNull($row['lease_expires_at']);
        $history = $this->historyOf($runId);
        self::assertSame(['running', 'succeeded'], array_column($history, 'event_type'), 'the dead attempt wrote none; the new one wrote both');
        self::assertNotContains($deadWorker, array_column($history, 'worker_id'));
        self::assertFalse(
            $this->runs->renewLease($runId, $deadWorker, 300),
            'the dead worker cannot renew a lease it lost',
        );
    }

    #[Test]
    public function a_single_run_whose_outcome_was_discarded_is_not_reported_as_a_success(): void
    {
        // scheduler:run-now --inline exited 0 after the lease was lost and its
        // result thrown away: the in-memory status still said succeeded.
        $runId = $this->dispatch();
        $run = $this->runs->findById($runId);
        self::assertNotNull($run);
        [$worker] = $this->worker(new LeaseLostAtFinalizeRunRepository($this->runs));

        self::assertFalse($worker->processSingle($run, 'run-now-inline-test'));
        self::assertCount(1, SchedulerEndToEndJob::$calls, 'the job did run');
    }

    /**
     * scheduler:run-now --inline said the run failed when only the history
     * row of its success could not be written: the run was already stored as
     * succeeded, and a retry by the operator would have run the job twice.
     */
    #[Test]
    public function a_single_run_whose_success_history_cannot_be_written_is_still_a_success(): void
    {
        $runId = $this->dispatch();
        $run = $this->runs->findById($runId);
        self::assertNotNull($run);
        // Writes run through the ORM's write engine (inside a transaction on
        // the pool), not the plain adapter: the engine is the seam.
        $refusingOrm = new OrmManager();
        (new \ReflectionProperty(OrmManager::class, 'aggregateWriteEngine'))->setValue(
            $refusingOrm,
            new AggregateWriteEngine(
                new InsertRefusedAdapter($this->orm->getAdapter()),
                $refusingOrm->getResourceModelHydrator(),
                $refusingOrm->getResourceModelMetadataRegistry(),
            ),
        );
        [$worker] = $this->worker(finalHistory: self::withOrm(new SchedulerRunHistoryRepository(), $refusingOrm));

        self::assertTrue($worker->processSingle($run, 'run-now-inline-test'));
        self::assertCount(1, SchedulerEndToEndJob::$calls, 'the job ran once');
        self::assertSame('succeeded', $this->row($runId)['status']);
        self::assertSame(['running'], array_column($this->historyOf($runId), 'event_type'), 'the success row is the one that was refused');
        self::assertStringContainsString(
            "Run '{$runId}' succeeded, but writing its history failed: RuntimeException: history refused",
            $this->log(),
        );
    }

    /**
     * The lock was released in finally: a release the database refused
     * escaped it and replaced the recorded success with an exception, so
     * scheduler:run-now --inline said the run failed.
     */
    #[Test]
    public function a_single_run_whose_lock_cannot_be_released_is_still_a_success(): void
    {
        $runId = $this->dispatch();
        $run = $this->runs->findById($runId);
        self::assertNotNull($run);
        $lockKey = $this->pool . ':lock';
        $run->setLockKey($lockKey);
        [$worker] = $this->worker(lockRepository: new ReleaseRefusedLockRepository($this->locks));

        self::assertTrue($worker->processSingle($run, 'run-now-inline-test'));
        self::assertCount(1, SchedulerEndToEndJob::$calls, 'the job ran once');
        self::assertSame('succeeded', $this->row($runId)['status']);
        self::assertSame(['running', 'succeeded'], array_column($this->historyOf($runId), 'event_type'));
        self::assertSame($runId, $this->locks->findByKey($lockKey)?->getRunId(), 'the lock is left to expire');
        self::assertStringContainsString(
            "Run '{$runId}': releasing lock '{$lockKey}' failed, it expires on its own: RuntimeException: release refused",
            $this->log(),
        );
    }

    /**
     * @return array{SchedulerWorker, DrainingRunRepository}
     */
    private function worker(
        ?ScheduledRunRepositoryInterface $runs = null,
        ?SchedulerRunHistoryRepository $finalHistory = null,
        ?SchedulerLockRepositoryInterface $lockRepository = null,
    ): array
    {
        $loop = new DrainingRunRepository($runs ?? $this->runs, $this->orm->getAdapter(), $this->pool);
        $config = new SchedulerConfig(defaultPool: $this->pool, pollIntervalSeconds: 1, leaseTtlSeconds: 300, lockTtlSeconds: 300);
        $leases = new RunLeaseManager($loop, $config->leaseTtlSeconds);
        $locks = new SchedulerLockManager($lockRepository ?? $this->locks, $config->lockTtlSeconds);

        $worker = new SchedulerWorker(
            leaseManager: $leases,
            lockManager: $locks,
            runRepository: $loop,
            overlapHandler: new OverlapPolicyHandler($loop, $locks, $this->definitions, $this->history),
            executor: new RunExecutor($loop, $this->history),
            retryScheduler: new RetryScheduler($loop, $this->history),
            historyRepository: $finalHistory ?? $this->history,
            config: $config,
        );
        $worker->setOutput($this->output);
        $loop->stopWhenDrained($worker);

        return [$worker, $loop];
    }

    /**
     * Dispatches through SchedulerService and answers with the id of the row
     * it wrote, read back from the pool rather than taken from dispatchAt():
     * the loop scenarios stay independent of the id regression test above.
     *
     * @param array<string, mixed> $payload
     */
    private function dispatch(
        array $payload = [],
        ?string $tenantId = null,
        int $maxAttempts = 1,
        int $retryBackoffSeconds = 0,
        string $jobClass = SchedulerEndToEndJob::class,
    ): string {
        $this->lastDispatchReturned = $this->scheduler->dispatchAt(
            jobClass: $jobClass,
            // In the past by more than DATETIME rounding: due on the first poll.
            runAt: new \DateTimeImmutable('-5 seconds'),
            payload: $payload,
            pool: $this->pool,
            tenantId: $tenantId,
            maxAttempts: $maxAttempts,
            retryBackoffSeconds: $retryBackoffSeconds,
        );

        $rows = $this->orm->getAdapter()->execute(
            'SELECT id FROM scheduler_runs WHERE pool = :pool ORDER BY id DESC LIMIT 1',
            ['pool' => $this->pool],
        )->rows;
        self::assertCount(1, $rows, 'dispatchAt() wrote a run into the pool');

        return Uuid7::fromBytes($rows[0]['id']);
    }

    /** @return array<string, mixed> */
    private function row(string $runId): array
    {
        $rows = $this->orm->getAdapter()->execute(
            'SELECT status, attempt_count, started_at, finished_at, available_at, lease_owner, lease_expires_at, last_error
             FROM scheduler_runs WHERE id = :id',
            ['id' => Uuid7::toBytes($runId)],
        )->rows;
        self::assertCount(1, $rows, "run {$runId} must exist");

        return $rows[0];
    }

    /** @return list<array{event_type: string, worker_id: ?string}> */
    private function historyOf(string $runId): array
    {
        // History ids are UUIDv7 minted on insert: byte order is write order.
        return $this->orm->getAdapter()->execute(
            'SELECT event_type, worker_id FROM scheduler_run_history WHERE run_id = :id ORDER BY id',
            ['id' => Uuid7::toBytes($runId)],
        )->rows;
    }

    /** Everything the workers logged so far (BufferedOutput::fetch() empties itself). */
    private function log(): string
    {
        return $this->logText .= $this->output->fetch();
    }

    private static function at(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
    }

    /**
     * @template T of object
     * @param T $repository
     * @return T
     */
    private static function withOrm(object $repository, OrmManager $orm): object
    {
        (new \ReflectionProperty($repository, 'orm'))->setValue($repository, $orm);

        return $repository;
    }

    private static function store(): InstanceStore
    {
        $container = ContainerFactory::get();
        self::assertInstanceOf(SemitexaContainer::class, $container);

        return (new \ReflectionProperty(SemitexaContainer::class, 'instanceStore'))->getValue($container);
    }
}

/** The job under test: records each call; a 'fail' payload makes it throw. */
final class SchedulerEndToEndJob implements ScheduledJobInterface
{
    /** @var list<array<string, mixed>> */
    public static array $calls = [];

    /** Run inside handle(), after the call is recorded: a test's hook into a job in progress. */
    public static ?\Closure $onHandle = null;

    public function handle(ScheduledJobContext $context): void
    {
        self::$calls[] = [
            'runId' => $context->runId,
            'attempt' => $context->attemptNumber,
            'pool' => $context->pool,
            'tenant' => $context->tenantId,
            'ambientTenant' => CoroutineContextStore::get()?->getTenantId(),
            'payload' => $context->payload,
        ];
        if (self::$onHandle !== null) {
            (self::$onHandle)();
        }

        if (isset($context->payload['fail'])) {
            throw new \RuntimeException($context->payload['fail'] . ' #' . $context->attemptNumber);
        }
        if (isset($context->payload['latin1'])) {
            throw new \RuntimeException("caf\xE9 down #" . $context->attemptNumber);
        }
    }
}

/** A service with a handle() method that is not a scheduled job. */
final class SchedulerNotAJob
{
    public static bool $called = false;

    public function handle(mixed $anything = null): void
    {
        self::$called = true;
    }
}

/**
 * Pass-through to the real repository that records every claim and stops the
 * worker once its pool holds nothing due within the next few seconds — so
 * run() returns when the work is done instead of polling forever. A deadline
 * stops a loop that never drains, and says so.
 */
final class LeaseLostAtFinalizeRunRepository implements ScheduledRunRepositoryInterface
{
    public function __construct(private readonly ScheduledRunRepositoryInterface $inner)
    {
    }

    public function findById(string $id): ?ScheduledRun
    {
        return $this->inner->findById($id);
    }

    public function findByOccurrenceKey(string $occurrenceKey): ?ScheduledRun
    {
        return $this->inner->findByOccurrenceKey($occurrenceKey);
    }

    public function save(ScheduledRun $entity): void
    {
        $this->inner->save($entity);
    }

    public function claimNextDue(string $pool, string $workerId, int $leaseTtlSeconds): ?string
    {
        return $this->inner->claimNextDue($pool, $workerId, $leaseTtlSeconds);
    }

    public function renewLease(string $runId, string $workerId, int $leaseTtlSeconds): bool
    {
        return $this->inner->renewLease($runId, $workerId, $leaseTtlSeconds);
    }

    public function finalizeIfOwned(ScheduledRun $run, ?string $expectedOwner): bool
    {
        return false;
    }

    public function reclaimExpiredLeases(\DateTimeImmutable $now): int
    {
        return $this->inner->reclaimExpiredLeases($now);
    }
}

final class DrainingRunRepository implements ScheduledRunRepositoryInterface
{
    /** @var list<?string> */
    public array $claims = [];
    public bool $deadlineHit = false;
    /** A stop asked mid-pass, as SIGTERM lands: while the pass reclaims leases, before it claims. */
    public bool $stopDuringPass = false;
    private ?SchedulerWorker $worker = null;
    private float $deadline = 0.0;

    public function __construct(
        private readonly ScheduledRunRepositoryInterface $inner,
        private readonly DatabaseAdapterInterface $db,
        private readonly string $pool,
    ) {}

    public function stopWhenDrained(SchedulerWorker $worker): void
    {
        $this->worker = $worker;
        $this->deadline = microtime(true) + 30.0;
    }

    public function claimNextDue(string $pool, string $workerId, int $leaseTtlSeconds): ?string
    {
        $claimed = $this->inner->claimNextDue($pool, $workerId, $leaseTtlSeconds);
        $this->claims[] = $claimed;

        if (microtime(true) > $this->deadline || count($this->claims) > 10) {
            $this->deadlineHit = true;
            $this->worker?->stop();
        } elseif ($claimed === null && !$this->somethingDueSoon()) {
            $this->worker?->stop();
        }

        return $claimed;
    }

    private function somethingDueSoon(): bool
    {
        // A retry with no backoff is due "now", which DATETIME may round up by a
        // second: keep polling while anything in the pool becomes due shortly.
        return $this->db->execute(
            "SELECT 1 FROM scheduler_runs
             WHERE pool = :pool AND status IN ('pending', 'retry_scheduled') AND available_at <= :soon
             LIMIT 1",
            ['pool' => $this->pool, 'soon' => (new \DateTimeImmutable('+3 seconds'))->format('Y-m-d H:i:s')],
        )->rows !== [];
    }

    public function findById(string $id): ?ScheduledRun
    {
        return $this->inner->findById($id);
    }

    public function findByOccurrenceKey(string $occurrenceKey): ?ScheduledRun
    {
        return $this->inner->findByOccurrenceKey($occurrenceKey);
    }

    public function save(ScheduledRun $entity): void
    {
        $this->inner->save($entity);
    }

    public function renewLease(string $runId, string $workerId, int $leaseTtlSeconds): bool
    {
        return $this->inner->renewLease($runId, $workerId, $leaseTtlSeconds);
    }

    public function finalizeIfOwned(ScheduledRun $run, ?string $expectedOwner): bool
    {
        return $this->inner->finalizeIfOwned($run, $expectedOwner);
    }

    public function reclaimExpiredLeases(\DateTimeImmutable $now): int
    {
        if ($this->stopDuringPass) {
            $this->worker?->stop();
        }
        return $this->inner->reclaimExpiredLeases($now);
    }
}

/**
 * Pass-through adapter that runs a callback once, just before the first
 * claiming UPDATE goes out — i.e. after the claim's SELECT picked its
 * candidate. That is the window two concurrent workers race in.
 */
final class InterleavingAdapter implements DatabaseAdapterInterface
{
    public bool $fired = false;
    public ?int $claimRowCount = null;

    public function __construct(
        private readonly DatabaseAdapterInterface $inner,
        private readonly \Closure $beforeFirstClaimUpdate,
    ) {}

    public function execute(string $sql, array $params = []): QueryResult
    {
        $isClaim = str_contains($sql, "SET status = 'claimed'");
        if ($isClaim && !$this->fired) {
            $this->fired = true;
            ($this->beforeFirstClaimUpdate)();
        }

        $result = $this->inner->execute($sql, $params);
        if ($isClaim && $this->claimRowCount === null) {
            $this->claimRowCount = $result->rowCount;
        }

        return $result;
    }

    public function supports(ServerCapability $capability): bool
    {
        return $this->inner->supports($capability);
    }

    public function getServerVersion(): string
    {
        return $this->inner->getServerVersion();
    }

    public function query(string $sql): QueryResult
    {
        return $this->inner->query($sql);
    }

    public function lastInsertId(): string
    {
        return $this->inner->lastInsertId();
    }
}

/** Pass-through whose finalize the database refuses, as strict MySQL refused invalid UTF-8. */
final class FinalizeRefusedRunRepository implements ScheduledRunRepositoryInterface
{
    public function __construct(private readonly ScheduledRunRepositoryInterface $inner)
    {
    }

    public function findById(string $id): ?ScheduledRun
    {
        return $this->inner->findById($id);
    }

    public function findByOccurrenceKey(string $occurrenceKey): ?ScheduledRun
    {
        return $this->inner->findByOccurrenceKey($occurrenceKey);
    }

    public function save(ScheduledRun $entity): void
    {
        $this->inner->save($entity);
    }

    public function claimNextDue(string $pool, string $workerId, int $leaseTtlSeconds): ?string
    {
        return $this->inner->claimNextDue($pool, $workerId, $leaseTtlSeconds);
    }

    public function renewLease(string $runId, string $workerId, int $leaseTtlSeconds): bool
    {
        return $this->inner->renewLease($runId, $workerId, $leaseTtlSeconds);
    }

    public function finalizeIfOwned(ScheduledRun $run, ?string $expectedOwner): bool
    {
        throw new \RuntimeException('refused');
    }

    public function reclaimExpiredLeases(\DateTimeImmutable $now): int
    {
        return $this->inner->reclaimExpiredLeases($now);
    }
}

/** Pass-through adapter that refuses every write: the database said no to the insert. */
final class InsertRefusedAdapter implements DatabaseAdapterInterface
{
    public function __construct(private readonly DatabaseAdapterInterface $inner)
    {
    }

    public function execute(string $sql, array $params = []): QueryResult
    {
        if (stripos(ltrim($sql), 'INSERT') === 0) {
            throw new \RuntimeException('history refused');
        }

        return $this->inner->execute($sql, $params);
    }

    public function supports(ServerCapability $capability): bool
    {
        return $this->inner->supports($capability);
    }

    public function getServerVersion(): string
    {
        return $this->inner->getServerVersion();
    }

    public function query(string $sql): QueryResult
    {
        return $this->inner->query($sql);
    }

    public function lastInsertId(): string
    {
        return $this->inner->lastInsertId();
    }
}

/** Pass-through lock repository whose release the database refuses. */
final class ReleaseRefusedLockRepository implements SchedulerLockRepositoryInterface
{
    public function __construct(private readonly SchedulerLockRepositoryInterface $inner)
    {
    }

    public function acquire(string $lockKey, string $runId, string $workerId, int $ttlSeconds): bool
    {
        return $this->inner->acquire($lockKey, $runId, $workerId, $ttlSeconds);
    }

    public function extend(string $lockKey, string $workerId, int $ttlSeconds): bool
    {
        return $this->inner->extend($lockKey, $workerId, $ttlSeconds);
    }

    public function release(string $lockKey, string $workerId): void
    {
        throw new \RuntimeException('release refused');
    }

    public function findByKey(string $lockKey): ?SchedulerLock
    {
        return $this->inner->findByKey($lockKey);
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return $this->inner->deleteExpired($now);
    }
}
