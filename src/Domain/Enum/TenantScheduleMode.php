<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Enum;

enum TenantScheduleMode: string
{
    case Global = 'global';
    case PerTenant = 'per_tenant';
    case ExplicitTenant = 'explicit_tenant';
}
