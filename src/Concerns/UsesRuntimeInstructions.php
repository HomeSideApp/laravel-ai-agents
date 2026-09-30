<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Concerns;

/**
 * Reusable implementation for AI components that accept runtime
 * instructions, storing them and exposing a helper to retrieve them.
 */
trait UsesRuntimeInstructions
{
    /** The composed instructions received for the current run, if any. */
    private ?string $runtimeInstructions = null;

    /**
     * Store the composed system instructions for this run.
     *
     * Satisfies the AcceptsRuntimeInstructions contract; called by
     * AiAgentManager just before prompt() so admin-editable prompts
     * (platform policy, user instructions, guardrail-sanitised context)
     * take effect without redeploying the agent class.
     *
     * @param  string  $instructions  The fully composed system prompt for
     *                                this run.
     */
    public function useRuntimeInstructions(string $instructions): void
    {
        $this->runtimeInstructions = $instructions;
    }

    /**
     * Retrieve the stored runtime instructions, falling back to a default.
     *
     * Agents implementing the SDK's Agent::instructions() call this so a
     * runtime override wins over the static in-code prompt, while the static
     * prompt remains the fallback when no runtime instructions were provided
     * (e.g. the agent was resolved outside the manager flow).
     *
     * @param  string  $defaultInstructions  The static instructions to use when
     *                                       no runtime override was stored.
     * @return string The effective instructions for this execution.
     */
    protected function runtimeInstructionsOr(string $defaultInstructions): string
    {
        return $this->runtimeInstructions ?? $defaultInstructions;
    }
}
