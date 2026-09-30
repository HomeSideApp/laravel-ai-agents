<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tenancy;

use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Enums\TenantIsolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds optional tenant ownership to a package model.
 *
 * When tenant support is disabled the trait is inert: no fillable column is
 * merged, the relationship resolves against the configured (or default)
 * model only when called, and scopes leave queries untouched.
 */
trait BelongsToTenant
{
    /**
     * Bootstrap the trait when the host model is instantiated.
     *
     * Called by Eloquent's `initialize*` convention on construction: merges
     * the configured tenant foreign key into $fillable so mass assignment
     * via create()/fill() accepts it — but only while tenant support is
     * active, keeping inert behaviour when disabled.
     */
    public function initializeBelongsToTenant(): void
    {
        $foreignKey = $this->tenantForeignKey();

        if ($this->tenantEnabled() && $foreignKey !== null) {
            $this->mergeFillable([$foreignKey]);
        }
    }

    /**
     * The tenant foreign key column name.
     *
     * Returns null when isolation is not `column` (in `database` mode the
     * column does not exist).
     */
    private function tenantForeignKey(): ?string
    {
        if (! $this->tenantEnabled()) {
            return null;
        }

        $key = config('ai-agents.tenant.foreign_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The owning tenant, resolved through the bound tenant resolver.
     *
     * The related model and foreign key come from the package configuration
     * at call time, so hosts keep their domain naming without subclassing.
     *
     * Throws in `database` mode because the tenant model lives in the host's
     * central database and the FK column does not exist on the scoped table.
     *
     * @return BelongsTo<Model, $this> A relation to the configured tenant
     *                                 model on the configured foreign key.
     *
     * @throws \LogicException When tenant support is disabled or isolation
     *                         is `database`.
     */
    public function tenant(): BelongsTo
    {
        /** @var ResolvesTenant $resolver */
        $resolver = app(ResolvesTenant::class);

        if ($resolver->isolation() !== TenantIsolation::Column) {
            throw new \LogicException(
                'The tenant() relation is only available in column-based isolation mode. In database mode, tenant context is resolved through the host\'s tenancy package.',
            );
        }

        $modelClass = $resolver->modelClass();

        if ($modelClass === null) {
            throw new \LogicException(
                'Tenant support is disabled or isolation is "database". Enable column-based tenancy via AI_AGENTS_TENANT_ISOLATION=column before using the tenant() relation.',
            );
        }

        return $this->belongsTo($modelClass, $resolver->foreignKey());
    }

    /**
     * Query scope: restrict results to the given tenant (delegates to the
     * bound tenant resolver, so custom resolvers apply their own rules).
     *
     * Usage: Model::forTenant($tenantId) / Model::forTenant(null) for
     * tenant-less rows.
     *
     * @param  Builder<Model>  $query  The builder being scoped.
     * @param  int|string|null  $tenantId  The tenant id to restrict to, or null
     *                                     to target rows with no tenant.
     * @return Builder<Model> The scoped builder.
     */
    public function scopeForTenant(Builder $query, int|string|null $tenantId): Builder
    {
        /** @var ResolvesTenant $resolver */
        $resolver = app(ResolvesTenant::class);

        return $resolver->scopeQuery($query, $tenantId);
    }

    /**
     * Whether tenant support is active in the current configuration.
     *
     * Returns true only when isolation is `column` (FK column exists).
     *
     * @return bool True when isolation is column, false for none/database.
     */
    protected function tenantEnabled(): bool
    {
        return TenantIsolation::fromConfig()->isColumn();
    }
}
