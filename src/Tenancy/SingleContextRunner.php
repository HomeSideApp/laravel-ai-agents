<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tenancy;

use HomeSide\AiAgents\Contracts\RunsForEachTenant;

/**
 * Default implementation: runs the callback exactly once.
 *
 * Used in `none` / `column` isolation modes and as the fallback when the
 * host does not provide a custom runner.
 */
final class SingleContextRunner implements RunsForEachTenant
{
    /**
     * Execute the callback once (no multi-tenancy iteration).
     */
    public function each(callable $callback): void
    {
        $callback();
    }
}
