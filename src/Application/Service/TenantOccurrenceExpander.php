<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Domain\Model\ScheduledOccurrence;
use Semitexa\Scheduler\Domain\Enum\TenantScheduleMode;
use Semitexa\Tenancy\Domain\Contract\TenantRepositoryInterface;

final class TenantOccurrenceExpander
{
    public function __construct(
        private readonly ?TenantRepositoryInterface $tenantRepository = null,
    ) {}

    /**
     * Expand a single occurrence into one-per-tenant when mode is PerTenant.
     *
     * An install with no tenancy at all — no repository wired, or none
     * configured — still gets exactly one occurrence, tenant-blind: "once per
     * tenant" on a single-site install means once. The older behaviour planned
     * nothing there, which is the worse failure of the two: the job stays
     * declared and listed and simply never runs, with nothing anywhere saying
     * so. A tenant-blind run writes to the same `default` scope that such an
     * install already serves.
     *
     * @return list<ScheduledOccurrence>
     */
    public function expand(ScheduledOccurrence $occurrence, TenantScheduleMode $mode): array
    {
        if ($mode !== TenantScheduleMode::PerTenant) {
            return [$occurrence];
        }

        $tenants = $this->tenantRepository?->findAll() ?? [];
        if ($tenants === []) {
            return [$occurrence];
        }

        $expanded = [];
        foreach ($tenants as $tenant) {
            $expanded[] = new ScheduledOccurrence(
                scheduleKey: $occurrence->scheduleKey,
                scheduledFor: $occurrence->scheduledFor,
                tenantId: $tenant->id,
            );
        }
        return $expanded;
    }
}
