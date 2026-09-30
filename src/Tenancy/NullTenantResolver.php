<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tenancy;

use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Enums\TenantIsolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * No-op tenant resolver used when tenant support is disabled.
 *
 * Bound by default so the rest of the package can always type-hint
 * ResolvesTenant without null checks.
 */
final class NullTenantResolver implements ResolvesTenant
{
    /**
     * Report tenant support as disabled.
     *
     * @return bool Always false: every consumer of the contract treats this
     *              installation as tenant-less.
     */
    public function enabled(): bool
    {
        return false;
    }

    /**
     * Report the isolation mode as "none".
     *
     * @return TenantIsolation::None Always the none variant; consumers that
     *                               check isolation() will skip all tenant
     *                               logic.
     */
    public function isolation(): TenantIsolation
    {
        return TenantIsolation::None;
    }

    /**
     * Report no tenant model.
     *
     * @return class-string|null Always null; consumers must not build
     *                           relationships or casts against a tenant.
     */
    public function modelClass(): ?string
    {
        return null;
    }

    /**
     * Provide a placeholder foreign key name.
     *
     * @return string Always 'tenant_id'; never a real column since writes
     *                are guarded by enabled() before touching it.
     */
    public function foreignKey(): string
    {
        return 'tenant_id';
    }

    /**
     * Provide a placeholder tenant table name.
     *
     * @return string Always 'tenants'; never queried because resolveAccessible()
     *                and scopeQuery() are no-ops.
     */
    public function table(): string
    {
        return 'tenants';
    }

    /**
     * Resolve nothing: no tenant is ever accessible without tenant support.
     *
     * @param  int|string|null  $userId  Ignored.
     * @param  int|string|null  $tenantId  Ignored.
     * @return int|string|null Always null.
     */
    public function resolveAccessible(int|string|null $userId, int|string|null $tenantId): int|string|null
    {
        return null;
    }

    /**
     * Leave the query untouched: tenant-less installations never filter.
     *
     * @param  Builder<Model>  $query  The query to scope (unchanged).
     * @param  int|string|null  $tenantId  Ignored.
     * @return Builder<Model> The exact query received.
     */
    public function scopeQuery(Builder $query, int|string|null $tenantId): Builder
    {
        return $query;
    }
}
