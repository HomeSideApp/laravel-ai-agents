<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Concerns;

use HomeSide\AiAgents\Configuration\Capability;
use HomeSide\AiAgents\Configuration\ModelCapabilities;

/**
 * Reusable implementation for agents that adapt to the resolved provider's
 * model capabilities (AcceptsProviderCapabilities).
 *
 * Stores the capabilities injected by AiAgentManager and exposes helpers:
 * - effectiveMaxTokens(int $base): scales the agent's token budget when the
 *   model reasons (thinking consumes the same budget before content).
 * - providerOptions(): SDK HasProviderOptions payload declaring the
 *   reasoning effort knob when the provider declares one.
 */
trait UsesProviderCapabilities
{
    private ?ModelCapabilities $providerCapabilities = null;

    /**
     * Receive the capabilities of the provider about to serve this run.
     */
    public function setProviderCapabilities(ModelCapabilities $capabilities): void
    {
        $this->providerCapabilities = $capabilities;
    }

    /**
     * The capabilities injected for this run, or null when the manager has
     * not provided them (e.g. the agent was resolved outside the run flow).
     */
    public function providerCapabilities(): ?ModelCapabilities
    {
        return $this->providerCapabilities;
    }

    /**
     * Scale the agent's token budget: reasoning models spend part of the
     * same max_tokens budget thinking before any content is emitted, so a
     * budget that fits the structured payload alone truncates output to
     * empty. Doubles the base when reasoning is declared.
     */
    public function effectiveMaxTokens(int $base): int
    {
        if ($this->providerCapabilities?->reasoning === true) {
            return $base * 2;
        }

        return $base;
    }

    /**
     * SDK HasProviderOptions payload: forward the provider's reasoning
     * effort knob when declared. Merged verbatim into the request body by
     * OpenAI-compatible gateways.
     *
     * @return array<string, mixed>
     */
    public function capabilityProviderOptions(): array
    {
        $effort = $this->providerCapabilities?->reasoningEffort;

        return $effort !== null && $effort !== ''
            ? ['reasoning_effort' => $effort]
            : [];
    }

    /**
     * Whether the resolved model can run the given provider (built-in) tool.
     *
     * Agents with optional provider tools (web search, code execution...)
     * call this to include a tool only when the model supports it. The SDK
     * already skips unsupported provider tools internally, but exposing the
     * check lets an agent adjust its prompt or fall back to a local tool
     * instead of silently losing the capability.
     */
    public function supportsProviderTool(Capability $capability): bool
    {
        return $this->providerCapabilities?->supportsProviderTool($capability) ?? false;
    }
}
