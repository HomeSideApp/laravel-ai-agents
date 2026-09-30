<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

/**
 * Contract for AI components that receive runtime instructions, such as
 * a system prompt, before execution.
 */
interface AcceptsRuntimeInstructions
{
    /**
     * Receive the composed runtime system instructions for this execution.
     *
     * Called by AiAgentManager right before prompt(): the compositor output
     * (platform policy + user instructions + context, with guardrails applied)
     * replaces whatever static instructions() the agent class declares, so
     * admin-editable prompts take effect without redeploying.
     *
     * @param  string  $instructions  The fully composed system prompt for
     *                                this run (multi-layer, guardrail-sanitised).
     */
    public function useRuntimeInstructions(string $instructions): void;
}
