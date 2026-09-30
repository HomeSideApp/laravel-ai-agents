<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tenancy;

use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Enums\TenantIsolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant resolver for database-per-tenant isolation (stancl/tenancy use-case).
 *
 * In this mode each tenant has its own database with the full set of `ai_*`
 * tables.  The resolver never adds `WHERE tenant_id = ?` conditions because
 * they are redundant — the connection itself isolates the tenant.
 *
 * `resolveAccessible()` returns the current tenant key **only** for metadata
 * recording (e.g. attaching a `tenant_key` annotation to runs/logs); it does
 * not drive query scoping.
 *
 * The key is resolved through the configurable callable
 * `ai-agents.tenant.current_key`, which the host wires to its tenancy
 * package's accessor (e.g. `fn () => tenant()?->getTenantKey()`).
 *
 * @see ResolvesTenant
 * @see TenantIsolation
 */
final class DatabaseTenantResolver implements ResolvesTenant
{
    /**
     * Always reports isolation as database-active.
     */
    public function enabled(): bool
    {
        return true;
    }

    /**
     * Report the isolation mode as "database".
     *
     * @return TenantIsolation::Database Always the database variant.
     */
    public function isolation(): TenantIsolation
    {
        return TenantIsolation::Database;
    }

    /**
     * No local tenant model: tenants are managed entirely by the host's
     * tenancy package (stancl/tenancy, etc.).
     *
     * @return class-string|null Always null.
     */
    public function modelClass(): ?string
    {
        return null;
    }

    /**
     * Placeholder: the FK column does not exist in database-per-tenant mode.
     *
     * @return string Always 'tenant_id'; never used for scoping.
     */
    public function foreignKey(): string
    {
        return 'tenant_id';
    }

    /**
     * Placeholder: the tenant table name is irrelevant in database-per-tenant
     * mode (each tenant's schema is managed by the host's tenancy package).
     *
     * @return string Always 'tenants'.
     */
    public function table(): string
    {
        return 'tenants';
    }

    /**
     * Resolve the current tenant key for metadata recording.
     *
     * Uses the configurable callable `ai-agents.tenant.current_key`. The host
     * wires this to its tenancy accessor (e.g. `fn () => tenant()?->getTenantKey()`).
     *
     * Ignores `$userId` — authorisation is handled by the host's tenancy
     * package.  Ignores explicit `$tenantId` — in database-per-tenant mode
     * the current tenant is determined by the active database connection.
     *
     * @param  int|string|null  $userId  Ignored.
     * @param  int|string|null  $tenantId  Ignored.
     * @return int|string|null The current tenant key, or null when not
     *                         available or the callable returns nothing useful.
     */
    public function resolveAccessible(int|string|null $userId, int|string|null $tenantId): int|string|null
    {
        $callable = config('ai-agents.tenant.current_key');

        if (is_callable($callable)) {
            $key = $callable();

            return is_int($key) || is_string($key) ? $key : null;
        }

        return null;
    }

    /**
     * No-op: each tenant's queries already hit its own database.
     *
     * @param  Builder<Model>  $query  The query (unchanged).
     * @param  int|string|null  $tenantId  Ignored.
     * @return Builder<Model> The exact query received.
     */
    public function scopeQuery(Builder $query, int|string|null $tenantId): Builder
    {
        return $query;
    }
}
