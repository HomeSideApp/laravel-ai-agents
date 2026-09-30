<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

use HomeSide\AiAgents\Enums\TenantIsolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for tenant resolution inside the package.
 *
 * Tenant support is optional. When disabled (config('ai-agents.tenant.enabled')
 * is false, and no explicit isolation mode is set), the package binds
 * NullTenantResolver and every method behaves as a no-op. Hosts with custom
 * membership rules may implement this contract and register the class-string
 * in config('ai-agents.tenant.resolver').
 *
 * The package supports three isolation modes, configured via
 * `ai-agents.tenant.isolation`:
 *
 * - `none`: no multi-tenancy.
 * - `column`: column-based tenant scoping via a foreign key on the `ai_*`
 *   tables (homeside/household use-case).
 * - `database`: separate database per tenant (stancl/tenancy use-case).
 *   The package queries the tenant's own database; the models.dev catalog
 *   lives on a central connection shared by every tenant.
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
     * Whether tenant scoping is active for this installation (any mode).
     *
     * True for both `column` and `database` isolation; false for `none`.
     */
    public function enabled(): bool;

    /**
     * The explicit isolation mode for this installation.
     */
    public function isolation(): TenantIsolation;

    /**
     * Class-string of the tenant model, or null when tenant support is disabled.
     *
     * @return class-string|null
     */
    public function modelClass(): ?string;

    /**
     * Column on the package tables holding the tenant id (e.g. 'household_id').
     *
     * Only meaningful when isolation is `column`; in `database` mode the
     * column does not exist because each tenant has its own database.
     */
    public function foreignKey(): string;

    /**
     * Table name of the tenant model (e.g. 'households').
     *
     * Only meaningful when isolation is `column`; in `database` mode the
     * tenant model lives in the host's central database.
     */
    public function table(): string;

    /**
     * Resolve the tenant usable for provider selection and run recording.
     *
     * In `column` mode: an explicit tenant identifier wins over the host's
     * "active tenant" column on the user model. When a user id is present,
     * the candidate tenant must still be authorised for that user before
     * it is eligible.
     *
     * In `database` mode: returns the current tenant key via the
     * `ai-agents.tenant.current_key` callable; the host's tenancy package
     * handles authorisation.
     *
     * @param  int|string|null  $userId  The user whose authorisation must be verified.
     * @param  int|string|null  $tenantId  The explicit tenant identifier, if any.
     * @return int|string|null The accessible tenant identifier, or null when none is eligible.
     */
    public function resolveAccessible(int|string|null $userId, int|string|null $tenantId): int|string|null;

    /**
     * Scope a query to the given tenant, or to rows without a tenant when null.
     *
     * A no-op in `database` mode because each tenant already has its own
     * database; the query runs against the correct tenant database by
     * construction.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeQuery(Builder $query, int|string|null $tenantId): Builder;
}
