<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Db\MySQL\Mapper\ScheduleDefinitionMapper;
use Semitexa\Scheduler\Application\Db\MySQL\Mapper\ScheduledRunMapper;
use Semitexa\Scheduler\Application\Db\MySQL\Mapper\SchedulerLockMapper;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerLockResource;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunResource;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerScheduleDefinitionResource;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
use Semitexa\Scheduler\Domain\Model\SchedulerLock;

/**
 * Every domain field must survive domain -> resource -> domain unchanged.
 * The mappers build resources via named-argument constructors (readonly
 * DTOs), so a field silently dropped from the constructor call would
 * come back as its default — this round-trip catches exactly that.
 */
final class MapperRoundTripTest extends TestCase
{
    #[Test]
    public function scheduled_run_round_trips_with_every_field_set(): void
    {
        $run = new ScheduledRun(
            id: 'run-id',
            sourceType: 'cron',
            scheduleDefinitionId: 'def-id',
            scheduleKey: 'reports.daily',
            occurrenceKey: 'reports.daily@2026-07-04T00:00:00Z',
            jobClass: 'Acme\\ReportsJob',
            tenantId: 'tenant-1',
            pool: 'reports',
            lockKey: 'scheduler:reports.daily',
            status: 'running',
            scheduledFor: new \DateTimeImmutable('2026-07-04 00:00:00'),
            availableAt: new \DateTimeImmutable('2026-07-04 00:00:01'),
            misfiredAt: new \DateTimeImmutable('2026-07-04 00:05:00'),
            attemptCount: 2,
            maxAttempts: 5,
            retryBackoffSeconds: 30,
            leaseOwner: 'worker-9',
            leaseExpiresAt: new \DateTimeImmutable('2026-07-04 00:10:00'),
            lockedAt: new \DateTimeImmutable('2026-07-04 00:00:02'),
            startedAt: new \DateTimeImmutable('2026-07-04 00:00:03'),
            finishedAt: new \DateTimeImmutable('2026-07-04 00:01:00'),
            lastHeartbeatAt: new \DateTimeImmutable('2026-07-04 00:00:30'),
            lastError: 'boom',
            payloadJson: '{"k":"v"}',
            createdAt: new \DateTimeImmutable('2026-07-03 23:59:00'),
            updatedAt: new \DateTimeImmutable('2026-07-04 00:00:30'),
        );

        $mapper = new ScheduledRunMapper();
        $resource = $mapper->toSourceModel($run);

        self::assertInstanceOf(SchedulerRunResource::class, $resource);
        self::assertEquals($run, $mapper->toDomain($resource));
    }

    #[Test]
    public function scheduler_lock_round_trips_with_every_field_set(): void
    {
        $lock = new SchedulerLock(
            id: 'lock-id',
            lockKey: 'scheduler:reports.daily',
            runId: 'run-id',
            workerId: 'worker-9',
            acquiredAt: new \DateTimeImmutable('2026-07-04 00:00:00'),
            expiresAt: new \DateTimeImmutable('2026-07-04 00:10:00'),
            createdAt: new \DateTimeImmutable('2026-07-04 00:00:00'),
            updatedAt: new \DateTimeImmutable('2026-07-04 00:00:00'),
        );

        $mapper = new SchedulerLockMapper();
        $resource = $mapper->toSourceModel($lock);

        self::assertInstanceOf(SchedulerLockResource::class, $resource);
        self::assertEquals($lock, $mapper->toDomain($resource));
    }

    #[Test]
    public function schedule_definition_round_trips_with_every_field_set(): void
    {
        $definition = new ScheduleDefinition(
            id: 'def-id',
            scheduleKey: 'reports.daily',
            jobClass: 'Acme\\ReportsJob',
            cronExpression: '0 3 * * *',
            timezone: 'Europe/Kyiv',
            pool: 'reports',
            overlapPolicy: 'queue',
            misfirePolicy: 'catch_up',
            tenantMode: 'per_tenant',
            maxCatchUpRuns: 3,
            maxAttempts: 5,
            retryBackoffSeconds: 60,
            enabled: false,
            planningCursorAt: new \DateTimeImmutable('2026-07-04 03:00:00'),
            lastPlannedAt: new \DateTimeImmutable('2026-07-04 03:00:01'),
            payloadTemplateJson: '{"tenant":"{{tenant}}"}',
            createdAt: new \DateTimeImmutable('2026-06-01 00:00:00'),
            updatedAt: new \DateTimeImmutable('2026-07-04 03:00:01'),
        );

        $mapper = new ScheduleDefinitionMapper();
        $resource = $mapper->toSourceModel($definition);

        self::assertInstanceOf(SchedulerScheduleDefinitionResource::class, $resource);
        self::assertEquals($definition, $mapper->toDomain($resource));
    }
}
