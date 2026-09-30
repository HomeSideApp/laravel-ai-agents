<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

use HomeSide\AiAgents\Configuration\ModelCapabilities;

/**
 * Contract for agents that adapt to the resolved provider's model
 * capabilities.
 *
 * AiAgentManager invokes setProviderCapabilities() right before the prompt
 * with the capabilities of the provider it resolved (declared in
 * ai_providers.configuration.model_capabilities, driver defaults as
 * baseline). Agents use it to size their token budget (reasoning models
 * consume it before content), pick tool-call formats, or add
 * provider-specific options via providerOptions().
 */
interface AcceptsProviderCapabilities
{
    /**
     * Receive the capabilities of the provider about to serve this run.
     */
    public function setProviderCapabilities(ModelCapabilities $capabilities): void;
}
