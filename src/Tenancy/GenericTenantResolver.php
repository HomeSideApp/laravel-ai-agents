<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tenancy;

use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Enums\TenantIsolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Config-driven tenant resolver.
 *
 * Implements the common case — a tenant model, a membership table and an
 * "active tenant" column on the user model — without any host code. The
 * host only provides configuration values (see config/ai-agents.php).
 *
 * User and tenant identifiers may be integers or UUIDs; every lookup is
 * performed through query bindings, never through type assumptions.
 *
 * Hosts with custom membership rules (role-based access, nested tenants,
 * invitations) should implement ResolvesTenant instead and register the
 * class-string in config('ai-agents.tenant.resolver').
 */
final class GenericTenantResolver implements ResolvesTenant
{
    /**
     * Report whether tenant support is switched on in the host config.
     *
     * @return bool True when isolation is `column`; false otherwise.
     */
    public function enabled(): bool
    {
        return $this->isolation()->isColumn();
    }

    /**
     * The isolation mode for this resolver: resolves from config, honouring
     * the backwards-compatible `tenant.enabled` alias.
     *
     * @return TenantIsolation The resolved isolation mode (column, database,
     *                         or none).
     */
    public function isolation(): TenantIsolation
    {
        return TenantIsolation::Column;
    }

    /**
     * Get the tenant model class-string from config.
     *
     * @return class-string|null The configured 'ai-agents.tenant.model' when
     *                           tenant support is active and the value is a
     *                           non-empty string; null otherwise (disables
     *                           relationships and morph-like usage).
     */
    public function modelClass(): ?string
    {
        $class = config('ai-agents.tenant.model');

        if (! $this->enabled() || ! is_string($class) || $class === '') {
            return null;
        }

        /** @var class-string $class */
        return $class;
    }

    /**
     * Get the physical column name holding the tenant id on package tables.
     *
     * Lets the host keep its domain naming ('household_id', 'team_id', ...)
     * instead of a package-enforced generic column.
     *
     * @return string The configured 'ai-agents.tenant.foreign_key', or
     *                'tenant_id' when unset/invalid.
     */
    public function foreignKey(): string
    {
        $key = config('ai-agents.tenant.foreign_key');

        return is_string($key) && $key !== '' ? $key : 'tenant_id';
    }

    /**
     * Get the tenant table name for foreign key targets and migrations.
     *
     * @return string The configured 'ai-agents.tenant.table', or 'tenants'
     *                when unset/invalid.
     */
    public function table(): string
    {
        $table = config('ai-agents.tenant.table');

        return is_string($table) && $table !== '' ? $table : 'tenants';
    }

    /**
     * Resolve the tenant the user may actually use for provider selection.
     *
     * Precedence: an explicit $tenantId wins over the host's "active tenant"
     * column on the user row. When a user id is present, the candidate must
     * pass the membership check (members table or permissive fallback) before
     * it is returned — closing the cross-tenant IDOR on provider selection.
     *
     * @param  int|string|null  $userId  The acting user's id (int or UUID); null
     *                                   short-circuits to the explicit tenant.
     * @param  int|string|null  $tenantId  An explicit tenant id (e.g. from the
     *                                     request), or null to fall back to the
     *                                     user's active tenant.
     * @return int|string|null The authorised tenant id (int or UUID as stored),
     *                         or null when support is off, no candidate resolves,
     *                         or membership fails.
     */
    public function resolveAccessible(int|string|null $userId, int|string|null $tenantId): int|string|null
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($userId === null) {
            return $tenantId;
        }

        $userColumn = config('ai-agents.tenant.user_column');

        $candidateTenantId = $tenantId
            ?? (is_string($userColumn) && $userColumn !== ''
                ? DB::table($this->usersTable())->where('id', $userId)->value($userColumn)
                : null);

        if (! is_string($candidateTenantId) && ! is_int($candidateTenantId)) {
            return null;
        }

        if (! $this->isMember($userId, $candidateTenantId)) {
            return null;
        }

        return $candidateTenantId;
    }

    /**
     * Restrict a query to one tenant, or to rows with no tenant when null.
     *
     * A no-op when tenant support is disabled so callers can chain without
     * conditionals.
     *
     * @param  Builder<Model>  $query  The query to scope.
     * @param  int|string|null  $tenantId  The tenant to restrict to; null targets
     *                                     rows where the foreign key is null.
     * @return Builder<Model> The scoped query.
     */
    public function scopeQuery(Builder $query, int|string|null $tenantId): Builder
    {
        if (! $this->enabled()) {
            return $query;
        }

        $foreignKey = $this->foreignKey();

        return $tenantId !== null
            ? $query->where($foreignKey, $tenantId)
            : $query->whereNull($foreignKey);
    }

    /**
     * Verify that the user is authorised to act inside the tenant.
     *
     * Uses the configured membership table when available; otherwise any
     * resolved candidate is accepted.
     */
    /**
     * Verify the user is authorised to act inside the candidate tenant.
     *
     * When 'members_table' is configured, requires an exact row match on the
     * membership keys; when it is not, accepts the candidate (the host opted
     * out of membership checks). Values are bound, never interpolated, so int
     * and UUID keys behave identically.
     *
     * @param  int|string  $userId  The acting user's id.
     * @param  int|string  $candidateTenantId  The tenant being authorised.
     * @return bool True when membership holds (or is not enforced).
     */
    private function isMember(int|string $userId, int|string $candidateTenantId): bool
    {
        $membersTable = config('ai-agents.tenant.members_table');

        if (! is_string($membersTable) || $membersTable === '') {
            return true;
        }

        $userKey = config('ai-agents.tenant.members_user_key', 'user_id');
        $tenantKey = config('ai-agents.tenant.members_tenant_key', $this->foreignKey());

        return DB::table($membersTable)
            ->where(is_string($userKey) && $userKey !== '' ? $userKey : 'user_id', $userId)
            ->where(is_string($tenantKey) && $tenantKey !== '' ? $tenantKey : $this->foreignKey(), $candidateTenantId)
            ->exists();
    }

    /**
     * Users table name (mirrors the core config key).
     */
    /**
     * Get the host's users table name for active-tenant lookups.
     *
     * Mirrors the core 'users_table' config key used by the package
     * migrations, keeping both lookups on the same table.
     *
     * @return string The configured table, or 'users' when unset/invalid.
     */
    private function usersTable(): string
    {
        $table = config('ai-agents.users_table');

        return is_string($table) && $table !== '' ? $table : 'users';
    }
}
