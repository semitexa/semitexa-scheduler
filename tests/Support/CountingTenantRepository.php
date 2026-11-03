<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Support;

use Semitexa\Tenancy\Domain\Contract\TenantRepositoryInterface;
use Semitexa\Tenancy\Domain\Model\Tenant;

/** A fixed list of tenants that counts how often all of them are read. */
final class CountingTenantRepository implements TenantRepositoryInterface
{
    public int $findAllCalls = 0;

    /** @var list<Tenant> */
    private array $tenants;

    public function __construct(Tenant ...$tenants)
    {
        $this->tenants = array_values($tenants);
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
        $this->findAllCalls++;

        return $this->tenants;
    }
}
