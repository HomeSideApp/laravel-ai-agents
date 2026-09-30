<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Enums;

/**
 * The explicit tenant isolation mode for this installation.
 *
 * - `none` (default): no multi-tenancy at all.
 * - `column`: column-based tenant scoping via a foreign key on the `ai_*`
 *   tables (homeside/household use-case).
 * - `database`: separate database per tenant (stancl/tenancy use-case).
 *   The package queries the tenant's own database; the models.dev catalog
 *   lives on a central connection shared by every tenant.
 *
 * The `tenant.enabled` config key remains as a backwards-compatible alias:
 * `tenant.enabled=true` without an explicit `isolation` value resolves to
 * `column`, preserving existing behaviour.
 */
enum TenantIsolation: string
{
    case None = 'none';
    case Column = 'column';
    case Database = 'database';

    /**
     * Resolve the active isolation mode from configuration, honouring
     * the backwards-compatible `tenant.enabled` alias.
     */
    public static function fromConfig(): self
    {
        $value = config('ai-agents.tenant.isolation');

        if (is_string($value) && $value !== '') {
            return self::tryFrom(strtolower($value)) ?? self::None;
        }

        // BC alias: tenant.enabled=true ⇒ column.
        return (bool) config('ai-agents.tenant.enabled', false)
            ? self::Column
            : self::None;
    }

    /**
     * Whether this installation uses any form of multi-tenancy.
     */
    public function isActive(): bool
    {
        return $this !== self::None;
    }

    /**
     * Whether tenant scoping is done via a foreign key column.
     */
    public function isColumn(): bool
    {
        return $this === self::Column;
    }

    /**
     * Whether each tenant has its own database.
     */
    public function isDatabase(): bool
    {
        return $this === self::Database;
    }
}
