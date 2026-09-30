<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tenancy;

use HomeSide\AiAgents\Contracts\RunsForEachTenant;
use Stancl\Tenancy\Tenancy;

/**
 * RunsForEachTenant implementation for stancl/tenancy.
 *
 * Iterates over all tenants (or all tenants with a specific key) and
 * executes the callback inside each tenant's context using
 * `Tenancy::runForMultiple`.
 *
 * This class is only registered when the stancl/tenancy package is
 * present and the isolation mode is `database`.
 */
final class StanclTenantRunner implements RunsForEachTenant
{
    /**
     * Execute the callback inside each tenant's database context.
     *
     * In stancl/tenancy `runForMultiple(null, ...)` iterates over all tenants
     * and temporarily switches the database connection for each one.
     *
     * @param  callable(): mixed  $callback  The code to execute per tenant.
     */
    public function each(callable $callback): void
    {
        // @phpstan-ignore-next-line Stancl\Tenancy may not be installed.
        Tenancy::runForMultiple(
            null,
            static function () use ($callback): void {
                $callback();
            },
        );
    }
}
