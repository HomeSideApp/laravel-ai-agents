<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for tenant resolution inside the package.
 *
 * Tenant support is optional. When disabled (config('ai-agents.tenant.enabled')
 * is false), the package binds NullTenantResolver and every method behaves
 * as a no-op. Hosts with custom membership rules may implement this contract
 * and register the class-string in config('ai-agents.tenant.resolver').
 *
 * The package never assumes what a tenant means: it may be a household,
 * team, workspace or organisation, depending on the host application.
 *
 * User and tenant identifiers are intentionally int|string: hosts may use
 * auto-increment integer keys or UUIDs. Nothing here requires a UUID.
 */
interface ResolvesTenant
{
    /**
     * Whether tenant scoping is active for this installation.
     */
    public function enabled(): bool;

    /**
     * Class-string of the tenant model, or null when tenant support is disabled.
     *
     * @return class-string|null
     */
    public function modelClass(): ?string;

    /**
     * Column on the package tables holding the tenant id (e.g. 'household_id').
     */
    public function foreignKey(): string;

    /**
     * Table name of the tenant model (e.g. 'households').
     */
    public function table(): string;

    /**
     * Resolve the tenant usable for provider selection and run recording.
     *
     * An explicit tenant identifier wins over the host's "active tenant"
     * column on the user model. When a user id is present, the candidate
     * tenant must still be authorised for that user before it is eligible.
     *
     * @param  int|string|null  $userId  The user whose authorisation must be verified.
     * @param  int|string|null  $tenantId  The explicit tenant identifier, if any.
     * @return int|string|null The accessible tenant identifier, or null when none is eligible.
     */
    public function resolveAccessible(int|string|null $userId, int|string|null $tenantId): int|string|null;

    /**
     * Scope a query to the given tenant, or to rows without a tenant when null.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeQuery(Builder $query, int|string|null $tenantId): Builder;
}
