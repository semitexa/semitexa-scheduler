<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Service\TenantOccurrenceExpander;
use Semitexa\Scheduler\Domain\Enum\TenantScheduleMode;
use Semitexa\Scheduler\Domain\Model\ScheduledOccurrence;
use Semitexa\Tenancy\Domain\Contract\TenantRepositoryInterface;
use Semitexa\Tenancy\Domain\Model\Tenant;

/**
 * Per-tenant planning fan-out: a PerTenant occurrence becomes one
 * occurrence per registered tenant (tenant-scoped occurrence keys keep
 * them idempotent), Global passes through untouched, and a missing
 * tenant repository degrades to planning nothing rather than planning
 * a tenant-blind run.
 */
final class TenantOccurrenceExpanderTest extends TestCase
{
    #[Test]
    public function global_mode_passes_the_occurrence_through(): void
    {
        $occurrence = $this->occurrence();
        $expander = new TenantOccurrenceExpander($this->repositoryWith());

        self::assertSame([$occurrence], $expander->expand($occurrence, TenantScheduleMode::Global));
    }

    #[Test]
    public function per_tenant_mode_fans_out_one_occurrence_per_tenant(): void
    {
        $occurrence = $this->occurrence();
        $expander = new TenantOccurrenceExpander($this->repositoryWith(
            Tenant::create('framework', 'Framework'),
            Tenant::create('demo', 'Demo'),
        ));

        $expanded = $expander->expand($occurrence, TenantScheduleMode::PerTenant);

        self::assertCount(2, $expanded);
        self::assertSame(['framework', 'demo'], array_map(static fn (ScheduledOccurrence $o): ?string => $o->tenantId, $expanded));

        foreach ($expanded as $tenantOccurrence) {
            self::assertSame($occurrence->scheduleKey, $tenantOccurrence->scheduleKey);
            self::assertSame($occurrence->scheduledFor, $tenantOccurrence->scheduledFor);
            self::assertStringContainsString(
                'tenant:' . $tenantOccurrence->tenantId,
                $tenantOccurrence->occurrenceKey(),
                'Tenant-scoped occurrence keys keep per-tenant planning idempotent.',
            );
        }
    }

    #[Test]
    public function per_tenant_mode_without_a_repository_plans_nothing(): void
    {
        $expander = new TenantOccurrenceExpander(null);

        self::assertSame([], $expander->expand($this->occurrence(), TenantScheduleMode::PerTenant));
    }

    #[Test]
    public function per_tenant_mode_with_no_tenants_plans_nothing(): void
    {
        $expander = new TenantOccurrenceExpander($this->repositoryWith());

        self::assertSame([], $expander->expand($this->occurrence(), TenantScheduleMode::PerTenant));
    }

    private function occurrence(): ScheduledOccurrence
    {
        return new ScheduledOccurrence(
            scheduleKey: 'reports.daily',
            scheduledFor: new \DateTimeImmutable('2026-07-04 03:00:00'),
        );
    }

    private function repositoryWith(Tenant ...$tenants): TenantRepositoryInterface
    {
        return new class ($tenants) implements TenantRepositoryInterface {
            /** @param array<Tenant> $tenants */
            public function __construct(private readonly array $tenants)
            {
            }

            public function find(string $id): ?Tenant
            {
                foreach ($this->tenants as $tenant) {
                    if ($tenant->id === $id) {
                        return $tenant;
                    }
                }

                return null;
            }

            public function exists(string $id): bool
            {
                return $this->find($id) !== null;
            }

            public function findActive(string $id): ?Tenant
            {
                return $this->find($id);
            }

            public function findAll(): array
            {
                return $this->tenants;
            }
        };
    }
}
