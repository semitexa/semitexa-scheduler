<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Db\MySQL\Mapper\ScheduleDefinitionMapper;
use Semitexa\Scheduler\Application\Db\MySQL\Mapper\ScheduledRunMapper;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Application\Service\Hydration\TypeCaster;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Domain\Model\ColumnDefinition;
use Semitexa\Scheduler\Application\Db\MySQL\Mapper\SchedulerLockMapper;
use Semitexa\Scheduler\Application\Db\MySQL\Mapper\SchedulerRunHistoryMapper;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerLockResource;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunHistoryResource;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunResource;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerScheduleDefinitionResource;
use Semitexa\Scheduler\Domain\Model\RunHistoryEntry;
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
    /**
     * The one the plain round trip could not catch.
     *
     * This mapper converted the BINARY(16) ids itself, in both directions — so
     * mapper-out then mapper-in cancelled and any round-trip test stayed green
     * while every scheduled job died in production. What sits between the two
     * halves in real life is the ORM, which converts that column type ON ITS
     * OWN: `castToDb` on the way in, `castFromDb` on the way out. Put the real
     * TypeCaster in the middle and the failure is immediate — the mapper is
     * handed the 36-character string the hydrator produced and Uuid7::fromBytes
     * answers «Expected 16 bytes, got 36».
     *
     * Guarded going forward by `semitexa.mapperTypeConversion`, which refuses
     * the call outright. This is the behavioural half of the same rule.
     */
    #[Test]
    public function run_history_survives_the_conversions_the_orm_performs_around_it(): void
    {
        $entry = new RunHistoryEntry(
            id: Uuid7::generate(),
            runId: Uuid7::generate(),
            eventType: 'status_changed',
            fromStatus: 'queued',
            toStatus: 'running',
            workerId: 'worker-9',
            message: 'claimed',
            context: ['attempt' => 2],
            createdAt: new \DateTimeImmutable('2026-07-04 00:00:03'),
            updatedAt: new \DateTimeImmutable('2026-07-04 00:00:03'),
        );

        $mapper = new SchedulerRunHistoryMapper();
        $resource = $mapper->toSourceModel($entry);

        self::assertInstanceOf(SchedulerRunHistoryResource::class, $resource);

        $caster = new TypeCaster();
        $column = static fn (string $name): ColumnDefinition => new ColumnDefinition(
            name: $name,
            type: MySqlType::Binary,
            phpType: 'string',
            length: 16,
        );

        // What the write engine stores, and what the read path then hands back.
        $storedId = $caster->castToDb($resource->id, $column('id'));
        $storedRunId = $caster->castToDb($resource->run_id, $column('run_id'));

        self::assertSame(16, strlen((string) $storedId), 'the column is BINARY(16) and must receive 16 bytes');
        self::assertSame(16, strlen((string) $storedRunId));

        $hydrated = new SchedulerRunHistoryResource(
            id: (string) $caster->castFromDb($storedId, $column('id')),
            run_id: (string) $caster->castFromDb($storedRunId, $column('run_id')),
            event_type: $resource->event_type,
            from_status: $resource->from_status,
            to_status: $resource->to_status,
            worker_id: $resource->worker_id,
            message: $resource->message,
            context_json: $resource->context_json,
            created_at: $resource->created_at,
            updated_at: $resource->updated_at,
        );

        self::assertEquals($entry, $mapper->toDomain($hydrated));
    }

    /** And the ordinary round trip, which the history mapper never had. */
    #[Test]
    public function run_history_round_trips_with_every_field_set(): void
    {
        $entry = new RunHistoryEntry(
            id: Uuid7::generate(),
            runId: Uuid7::generate(),
            eventType: 'failed',
            fromStatus: 'running',
            toStatus: 'failed',
            workerId: 'worker-1',
            message: 'boom',
            context: ['error' => 'boom', 'attempt' => 3],
            createdAt: new \DateTimeImmutable('2026-07-04 00:01:00'),
            updatedAt: new \DateTimeImmutable('2026-07-04 00:01:00'),
        );

        $mapper = new SchedulerRunHistoryMapper();

        self::assertEquals($entry, $mapper->toDomain($mapper->toSourceModel($entry)));
    }
}
