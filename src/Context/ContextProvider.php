<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Context;

use HomeSide\AiAgents\Execution\AiExecutionContextData;

/**
 * Contract for module context providers.
 *
 * Each provider supplies relevant context to the agent (small data, no data
 * dumps). Potentially large data → use Tools instead.
 */
interface ContextProvider
{
    /**
     * Unique provider identifier (e.g. 'user', 'tenant', 'recipes', 'products').
     */
    public function key(): string;

    /**
     * Provide context data for the agent execution.
     *
     * @return array{key: string, data: mixed}
     */
    public function provide(AiExecutionContextData $context): array;
}
