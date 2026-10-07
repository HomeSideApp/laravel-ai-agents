<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Configuration;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\Contracts\DomainAgent;
use HomeSide\AiAgents\Enums\TenantIsolation;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiAgent;
use HomeSide\AiAgents\Models\AiGlobalSetting;
use HomeSide\AiAgents\Models\ModuleAiConfiguration;
use HomeSide\AiAgents\Synchronizer\AgentSynchronizer;
use InvalidArgumentException;

/**
 * Resolves the complete configuration for an agent execution by combining:
 *
 * 1. Agent class defaults (code)
 * 2. AiAgent row (platform admin)
 * 3. AiGlobalSetting (optional global platform policy)
 * 4. ModuleAiConfiguration (user-level)
 * 5. Parameter precedence: user → global → agent defaults
 *
 * The resolved system prompt is built by PromptCompositor; this resolver
 * only gathers the raw inputs and merges parameters.
 */
class AgentConfigurationResolver
{
    public function __construct(
        protected readonly AgentRegistry $registry,
    ) {}

    /**
     * Resolve the full configuration for an agent execution.
     *
     * @param  string  $agentKey  The agent key (e.g. 'recipes.recipe_generator')
     * @param  AiExecutionContextData  $context  The execution context
     * @return ResolvedAgentConfigurationData The merged configuration.
     */
    public function resolve(
        string $agentKey,
        AiExecutionContextData $context,
    ): ResolvedAgentConfigurationData {
        $agent = $this->registry->get($agentKey);

        // 1. Load the platform-managed row, creating it on demand when the
        // registry knows the agent but the database row is missing (fresh
        // deploy, restored database...). This keeps the sync transparent:
        // the run proceeds with the class defaults instead of failing.
        $aiAgent = AiAgent::findByKey($agentKey) ?? $this->syncMissingAgent($agentKey);

        if ($aiAgent === null) {
            // syncMissingAgent() returns null only when the registry does
            // not know the key or the database is unavailable — either way
            // the run cannot honour the platform configuration.
            throw new InvalidArgumentException(
                "No AiAgent row found for key '{$agentKey}'. Run AgentSynchronizer first.",
            );
        }

        // 2. Load the optional global platform policy.
        $globalPrompt = AiGlobalSetting::query()->global()->value('extra_prompt');

        // 3. Load user-level configuration, scoped to the tenant when tenant support is enabled.
        $userConfig = $this->resolveUserConfig($agentKey, $context);

        // 4. Merge parameters with precedence.
        $parameters = $this->mergeParameters(
            agent: $agent,
            aiAgent: $aiAgent,
            userConfig: $userConfig,
        );

        return new ResolvedAgentConfigurationData(
            agent: $agentKey,
            agentVersion: $agent->version(),
            provider: '', // Resolved later by ProviderResolver
            model: '', // Resolved later by ProviderResolver
            parameters: $parameters,
            effectiveInstructions: '', // Built by PromptCompositor
            enabled: $aiAgent->enabled && ($userConfig->enabled ?? true),
            providerId: $userConfig?->ai_provider_id,
            configuredModel: $userConfig?->model,
            scopeParameters: is_array($userConfig?->parameters) ? $userConfig->parameters : [],
            configuredProviderModelId: $userConfig?->provider_model_id,
        );
    }

    /**
     * Get the platform prompt for an agent.
     */
    public function getPlatformPrompt(string $agentKey): string
    {
        $aiAgent = AiAgent::findByKey($agentKey) ?? $this->syncMissingAgent($agentKey);

        if ($aiAgent === null) {
            throw new InvalidArgumentException(
                "No AiAgent row found for key '{$agentKey}'.",
            );
        }

        return $aiAgent->platform_prompt;
    }

    /**
     * Create the missing ai_agents row for a registered agent on demand.
     *
     * Delegates to the synchroniser so the seeding logic (platform prompt
     * from the class, label/description/parameters from AgentMetadata)
     * stays in one place. Returns the fresh row, or null when the agent is
     * not registered — a caller treats that as a configuration error.
     */
    private function syncMissingAgent(string $agentKey): ?AiAgent
    {
        if (! $this->registry->has($agentKey)) {
            return null;
        }

        try {
            app(AgentSynchronizer::class)->sync();
        } catch (\Throwable) {
            // Database unavailable: fall through, the caller reports it.
            return null;
        }

        return AiAgent::findByKey($agentKey);
    }

    /**
     * Get the per-scope additional instructions for an agent.
     *
     * Two configuration ownership models are supported, mirroring the
     * provider resolver's scope chain:
     *
     * - user-owned rows (`user_id` set): the package default, edited per
     *   user (optionally tenant-scoped while tenancy is enabled).
     * - tenant-owned rows (the tenant FK set, `user_id` null): hosts whose
     *   per-scope configuration is managed collectively for the whole
     *   household/team — the same tenancy system, a different owner.
     *
     * Precedence is user → tenant, so a personal configuration wins over
     * the tenant-wide one. The tenant id is the *authorised* tenant from
     * the execution context (GenericTenantResolver verified membership).
     *
     * @param  int|string|null  $userId  Host user identifier (integer or UUID).
     * @param  int|string|null  $tenantId  Authorised tenant id from the execution context, when available.
     */
    public function getUserInstructions(
        string $agentKey,
        int|string|null $userId,
        int|string|null $tenantId = null,
    ): ?string {
        $config = $this->resolveScopeConfig($agentKey, $userId, $tenantId);

        return $config?->additional_instructions;
    }

    /**
     * Resolve the scope configuration row for an agent: user-owned first,
     * then tenant-owned. Null when neither exists.
     */
    protected function resolveScopeConfig(
        string $agentKey,
        int|string|null $userId,
        int|string|null $tenantId,
    ): ?ModuleAiConfiguration {
        $parts = explode('.', $agentKey);
        $module = $parts[0];
        $agentName = $parts[1] ?? $agentKey;

        if ($userId !== null) {
            $tenantForeignKey = $this->tenantForeignKey();
            $userConfig = ModuleAiConfiguration::query()
                ->where('module', $module)
                ->where('agent_name', $agentName)
                ->where('user_id', $userId);

            if ($tenantForeignKey !== null) {
                if ($tenantId === null) {
                    $userConfig->whereNull($tenantForeignKey);
                } else {
                    $userConfig->where(function ($scope) use ($tenantForeignKey, $tenantId): void {
                        $scope->where($tenantForeignKey, $tenantId)->orWhereNull($tenantForeignKey);
                    })->orderByDesc($tenantForeignKey);
                }
            }

            $userConfig = $userConfig->first();

            if ($userConfig !== null) {
                return $userConfig;
            }
        }

        if ($tenantId === null) {
            return null;
        }

        $tenantForeignKey = $this->tenantForeignKey();

        if ($tenantForeignKey === null) {
            return null;
        }

        $tenantConfig = ModuleAiConfiguration::query()
            ->where('module', $module)
            ->where('agent_name', $agentName)
            ->whereNull('user_id')
            ->where($tenantForeignKey, $tenantId)
            ->first();

        return $tenantConfig;
    }

    /**
     * The configured tenant foreign key column, or null when isolation is not
     * `column` (in `database` mode the FK column does not exist).
     */
    private function tenantForeignKey(): ?string
    {
        if (TenantIsolation::fromConfig() !== TenantIsolation::Column) {
            return null;
        }

        $key = config('ai-agents.tenant.foreign_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Resolve scope-level configuration for an agent: the user-owned row
     * first, then the tenant-owned row (config managed collectively for the
     * household/team), mirroring getUserInstructions(). The tenant id comes
     * from the execution context already authorised by the manager.
     *
     * @param  AiExecutionContextData  $context  The execution context.
     */
    protected function resolveUserConfig(
        string $agentKey,
        AiExecutionContextData $context,
    ): ?ModuleAiConfiguration {
        return $this->resolveScopeConfig($agentKey, $context->userId, $context->tenantId);
    }

    /**
     * Merge parameters from all layers with precedence.
     *
     * Later sources win: user configuration overrides the platform ai_agents
     * row, which overrides the agent class defaults, which override the
     * package baseline. Keys outside the baseline set are dropped from the
     * agent defaults so arbitrary code-supplied keys cannot leak through.
     *
     * @param  DomainAgent  $agent  The agent providing class defaults.
     * @param  AiAgent  $aiAgent  The platform row (may carry overrides).
     * @param  ModuleAiConfiguration|null  $userConfig  The user-level row, if any.
     * @return array{temperature: float, top_p: float, max_tokens: int, max_steps: int, timeout: int} The merged parameters.
     */
    protected function mergeParameters(
        DomainAgent $agent,
        AiAgent $aiAgent,
        ?ModuleAiConfiguration $userConfig,
    ): array {
        $defaults = [
            'temperature' => 0.5,
            'top_p' => 1.0,
            'max_tokens' => 2048,
            'max_steps' => 1,
            'timeout' => 90,
        ];

        $agentDefaults = $agent->defaultConfiguration();
        $agentDefaults = array_intersect_key($agentDefaults, $defaults);

        $aiAgentParams = is_array($aiAgent->parameters) ? $aiAgent->parameters : [];
        $userParams = is_array($userConfig?->parameters) ? $userConfig->parameters : [];

        /** @var array{temperature: float, top_p: float, max_tokens: int, max_steps: int, timeout: int} $merged */
        $merged = array_merge($defaults, $agentDefaults, $aiAgentParams, $userParams);

        return $merged;
    }
}
