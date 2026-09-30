<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

/**
 * Contract for iterating command execution across tenant databases.
 *
 * In `database` isolation mode each tenant has its own database, so commands
 * that modify scoped `ai_*` tables must run once per tenant.  In `none` /
 * `column` modes the callback runs exactly once (against the single
 * database).
 *
 * Hosts using a custom tenancy package may implement this contract and
 * register the class-string via `config('ai-agents.tenant.runner')`.
 */
interface RunsForEachTenant
{
    /**
     * Execute the callback once per tenant (or once overall when no
     * multi-tenancy is active).
     *
     * @param  callable(): mixed  $callback  The code to execute.
     */
    public function each(callable $callback): void;
}
