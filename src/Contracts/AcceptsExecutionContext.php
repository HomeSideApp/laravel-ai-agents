<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

use HomeSide\AiAgents\Execution\AiExecutionContextData;

/**
 * Contract for agents that need the per-request execution context
 * (user, tenant, conversation) before they can build their tools.
 *
 * Agents implementing this contract receive the context right before
 * execution, so tools constructed with it always operate on the
 * authenticated user's identity instead of an empty placeholder.
 */
interface AcceptsExecutionContext
{
    /**
     * Set the per-request execution context before the agent builds its tools.
     */
    public function setExecutionContext(AiExecutionContextData $context): void;
}
