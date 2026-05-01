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
     * @return list<ScheduledOccurrence>
     */
    public function expand(ScheduledOccurrence $occurrence, TenantScheduleMode $mode): array
    {
        if ($mode !== TenantScheduleMode::PerTenant) {
            return [$occurrence];
        }

        if ($this->tenantRepository === null) {
            return [];
        }

        $tenants = $this->tenantRepository->findAll();
        if ($tenants === []) {
            return [];
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
