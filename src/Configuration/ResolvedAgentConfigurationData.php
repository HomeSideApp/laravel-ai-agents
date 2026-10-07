<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Configuration;

/**
 * Resolved configuration of an agent after applying overrides.
 *
 * Result of AgentConfigurationResolver: combines the agent defaults, sparse
 * overrides (user → tenant → global), and the selected provider/model.
 */
final readonly class ResolvedAgentConfigurationData
{
    /**
     * @param  string  $agent  The agent key.
     * @param  int  $agentVersion  The agent version.
     * @param  string  $provider  The provider name registered in the SDK.
     * @param  string  $model  The selected model.
     * @param  array{temperature?: float, top_p?: float, max_tokens?: int, max_steps?: int, timeout?: int}  $parameters
     * @param  string  $effectiveInstructions  Composite instructions (all layers).
     * @param  string  $fallbackPolicy  The provider fallback policy.
     * @param  bool  $enabled  Whether the agent is enabled.
     */
    public function __construct(
        public string $agent,
        public int $agentVersion,
        public string $provider,
        public string $model,
        /** @var array{temperature?: float, top_p?: float, max_tokens?: int, max_steps?: int, timeout?: int} */
        public array $parameters,
        public string $effectiveInstructions,
        public ?string $fallbackPolicy = null,
        public bool $enabled = true,
        public ?string $providerId = null,
        public ?string $configuredModel = null,
        /** @var array<string, mixed> */
        public array $scopeParameters = [],
        public ?string $configuredProviderModelId = null,
    ) {}

    /**
     * Derive a copy with the fallback policy resolved from the chosen
     * provider. The DTO is readonly, so mutation is expressed as a clone.
     *
     * @param  string|null  $fallbackPolicy  The chosen provider's raw
     *                                       fallback_policy column value
     *                                       (null keeps the field null).
     */
    public function withFallbackPolicy(?string $fallbackPolicy): self
    {
        return new self(
            agent: $this->agent,
            agentVersion: $this->agentVersion,
            provider: $this->provider,
            model: $this->model,
            parameters: $this->parameters,
            effectiveInstructions: $this->effectiveInstructions,
            fallbackPolicy: $fallbackPolicy,
            enabled: $this->enabled,
            providerId: $this->providerId,
            configuredModel: $this->configuredModel,
            scopeParameters: $this->scopeParameters,
            configuredProviderModelId: $this->configuredProviderModelId,
        );
    }

    /**
     * Serialize the resolved configuration to a plain array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'agent' => $this->agent,
            'agent_version' => $this->agentVersion,
            'provider' => $this->provider,
            'model' => $this->model,
            'parameters' => $this->parameters,
            'effective_instructions' => $this->effectiveInstructions,
            'fallback_policy' => $this->fallbackPolicy,
            'enabled' => $this->enabled,
            'provider_id' => $this->providerId,
            'configured_model' => $this->configuredModel,
            'configured_provider_model_id' => $this->configuredProviderModelId,
        ];
    }
}
