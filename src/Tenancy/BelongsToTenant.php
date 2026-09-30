<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tenancy;

use HomeSide\AiAgents\Contracts\ResolvesTenant;
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
     * The owning tenant, resolved through the bound tenant resolver.
     *
     * The related model and foreign key come from the package configuration
     * at call time, so hosts keep their domain naming without subclassing.
     *
     * @return BelongsTo<Model, $this> A relation to the configured tenant
     *                                 model on the configured foreign key.
     *
     * @throws \LogicException When tenant support is disabled — calling a
     *                         relation that has no model to target is a
     *                         programming error, not a runtime condition.
     */
    public function tenant(): BelongsTo
    {
        /** @var ResolvesTenant $resolver */
        $resolver = app(ResolvesTenant::class);

        $modelClass = $resolver->modelClass();

        if ($modelClass === null) {
            throw new \LogicException(
                'Tenant support is disabled. Enable it via AI_AGENTS_TENANT_ENABLED before using the tenant() relation.',
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
     * @return bool True when config('ai-agents.tenant.enabled') is truthy.
     */
    protected function tenantEnabled(): bool
    {
        return (bool) config('ai-agents.tenant.enabled', false);
    }

    /**
     * The configured tenant foreign key column name.
     *
     * @return string|null The configured 'ai-agents.tenant.foreign_key' when
     *                     it is a non-empty string; null otherwise (signals
     *                     "do not touch tenant columns").
     */
    protected function tenantForeignKey(): ?string
    {
        $key = config('ai-agents.tenant.foreign_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
