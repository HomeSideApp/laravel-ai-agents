<?php

declare(strict_types=1);

namespace HomeSide\AiAgents;

use HomeSide\AiAgents\Configuration\AgentConfigurationResolver;
use HomeSide\AiAgents\Configuration\ModelCapabilities;
use HomeSide\AiAgents\Context\ContextBuilder;
use HomeSide\AiAgents\Contracts\AcceptsExecutionContext;
use HomeSide\AiAgents\Contracts\AcceptsProviderCapabilities;
use HomeSide\AiAgents\Contracts\AcceptsRuntimeConfiguration;
use HomeSide\AiAgents\Contracts\AcceptsRuntimeInstructions;
use HomeSide\AiAgents\Contracts\DomainAgent;
use HomeSide\AiAgents\Contracts\InspectsPrompt;
use HomeSide\AiAgents\Enums\AiDriver;
use HomeSide\AiAgents\Enums\PrivacyLevel;
use HomeSide\AiAgents\Exceptions\NoAiProviderException;
use HomeSide\AiAgents\Exceptions\PrivacyViolationException;
use HomeSide\AiAgents\Exceptions\PromptInjectionBlockedException;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Execution\AiExecutionResultData;
use HomeSide\AiAgents\Execution\AiUsageData;
use HomeSide\AiAgents\Execution\ExecutionRecorder;
use HomeSide\AiAgents\Models\AiAgent;
use HomeSide\AiAgents\Models\AiRun;
use HomeSide\AiAgents\Prompting\PromptCompositor;
use HomeSide\AiAgents\Providers\DynamicProviderRegistrar;
use HomeSide\AiAgents\Providers\ProviderResolver;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

/**
 * Single entry point for running agents.
 *
 * Orchestrates: registry → config → capabilities → provider → SSRF → prompt → context → SDK → recorder.
 *
 * Uses AgentConfigurationResolver to merge platform + tenant config,
 * and PromptCompositor to assemble the hierarchical prompt with guardrails.
 */
class AiAgentManager
{
    public function __construct(
        private readonly AgentRegistry $registry,
        private readonly ProviderResolver $providerResolver,
        private readonly DynamicProviderRegistrar $registrar,
        private readonly AgentConfigurationResolver $configResolver,
        private readonly PromptCompositor $promptCompositor,
        private readonly ContextBuilder $contextBuilder,
        private readonly ExecutionRecorder $recorder,
        private readonly InspectsPrompt $inspector,
    ) {}

    /**
     * Run an agent with the given context and prompt.
     *
     * @param  string  $agentKey  The agent key (e.g. 'recipes.recipe_generator').
     * @param  AiExecutionContextData  $context  The execution context.
     * @param  string  $userMessage  The user prompt (kept separate from system prompt).
     * @param  list<mixed>  $attachments  SDK attachments (e.g. Laravel\Ai\Files\LocalImage) for multimodal agents.
     * @return AiExecutionResultData The normalized recorded result of the AI execution.
     *
     * @throws RuntimeException If the agent does not exist, is disabled, there is no provider, or execution fails.
     */
    public function run(
        string $agentKey,
        AiExecutionContextData $context,
        string $userMessage,
        array $attachments = [],
        ?AiRun $existingRun = null,
    ): AiExecutionResultData {
        // 1. Resolve the agent from the registry.
        $agent = $this->registry->get($agentKey);

        // 2. Resolve the full configuration (platform + tenant + defaults).
        $config = $this->configResolver->resolve($agentKey, $context);

        if (! $config->enabled) {
            throw new RuntimeException(
                "The AI agent [{$agentKey}] is disabled.",
            );
        }

        // 3. Determine the module owned by the agent (plain string, host-defined).
        $module = $agent->module();

        // 4. Resolve the provider from the user-to-tenant-to-system hierarchy.
        // The chosen provider's fallback_policy governs privacy degradation.
        $provider = $config->providerId !== null
            ? $this->providerResolver->listAvailable($context->userId, $context->tenantId)
                ->first(fn ($candidate): bool => $candidate->id === $config->providerId
                    && $candidate->servesModule($module))
            : $this->providerResolver->resolve($module, $context->userId, $context->tenantId);

        if ($provider === null) {
            throw new NoAiProviderException(
                "No AI provider is configured for module [{$module}].",
            );
        }

        // 4b. Enforce the agent's minimum privacy level: a provider less
        // private than required aborts the run before any data leaves.
        $required = $agent->requiredPrivacyLevel();

        if ($required !== null
            && ! PrivacyLevel::fromColumn($provider->privacy_level)->isAtLeast($required)) {
            throw new PrivacyViolationException(
                "The AI agent [{$agentKey}] requires privacy level [{$required->value}], "
                ."but the resolved provider [{$provider->name}] has "
               ."[{$provider->privacy_level}].",
            );
        }

        // 5. Register the database provider with the SDK.
        $providerName = $this->registrar->register($provider);

        // 6. Use the provider's configured model.
        $modelName = $config->configuredModel ?? $provider->model;

        if ($modelName !== $provider->model
            && ! $provider->models()->where('model', $modelName)->where('enabled', true)->exists()) {
            throw new RuntimeException("The model [{$modelName}] is not enabled for provider [{$provider->name}].");
        }

        // 6b. Attach the resolved provider identity and its privacy fallback
        // policy to the configuration, replacing the placeholder values.
        $config = $config->withFallbackPolicy($provider->fallback_policy);

        // 7. Build the domain context.
        $domainContext = $this->contextBuilder->build($agent, $context);

        // 7b. Firewall: inspect the user message before any prompt assembly.
        // The bound inspector is a no-op when the firewall is disabled, so
        // there is no config check here — the finding decides.
        $finding = $this->inspector->inspect('user_message', $userMessage, $context);

        if ($finding->blocks()) {
            throw new PromptInjectionBlockedException(
                "The AI firewall blocked the agent [{$agentKey}]: injection patterns matched in the user message.",
            );
        }

        // 8. Compose the hierarchical prompt with guardrails.
        $composed = $this->promptCompositor->compose(
            agentKey: $agentKey,
            userMessage: $userMessage,
            domainContext: $domainContext,
            executionContext: $context,
        );

        // 9. Execute and record the run. The provider's privacy level drives
        // content retention (full/redacted/none) on the recorded run.
        $providerPrivacy = PrivacyLevel::fromColumn($provider->privacy_level);
        $run = $existingRun ?? $this->recorder->startRun($context, $agentKey, $userMessage, null, $providerPrivacy);
        if ($existingRun !== null) {
            $this->recorder->applyRetention($run, $providerPrivacy);
        }
        $run->update(['provider_id' => $provider->id, 'provider_name' => $providerName, 'model_name' => $modelName]);
        $start = microtime(true);

        try {
            // Resolve the SDK agent instance.
            $sdkAgent = $this->resolveSdkAgent($agent);

            if ($sdkAgent instanceof AcceptsExecutionContext) {
                $sdkAgent->setExecutionContext($context);
            }

            // Hand the resolved provider's declared model capabilities to the
            // agent so it can adapt (reasoning budget, tool-call format...).
            $capabilities = ModelCapabilities::fromProviderConfiguration(
                $provider->configuration,
                AiDriver::fromColumn($provider->type),
            );

            if ($sdkAgent instanceof AcceptsProviderCapabilities) {
                $sdkAgent->setProviderCapabilities($capabilities);
            }

            if ($sdkAgent instanceof AcceptsRuntimeInstructions) {
                $sdkAgent->useRuntimeInstructions($composed['system']);
            }

            if ($sdkAgent instanceof AcceptsRuntimeConfiguration) {
                $runtimeParameters = $config->parameters;
                $providerTemperature = $provider->configuration['temperature'] ?? null;

                if (is_numeric($providerTemperature)
                    && (float) $providerTemperature >= 0
                    && (float) $providerTemperature <= 2
                    && ! isset($config->scopeParameters['temperature'])) {
                    $runtimeParameters['temperature'] = (float) $providerTemperature;
                }

                $sdkAgent->setRuntimeConfiguration($runtimeParameters);
            }

            $timeoutValue = $config->parameters['timeout'] ?? null;
            $timeout = is_numeric($timeoutValue) ? (int) $timeoutValue : 90;

            $response = $sdkAgent->prompt(
                $composed['user'],
                attachments: $attachments,
                provider: $providerName,
                model: $modelName,
                timeout: $timeout,
            );

            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $usage = $response->usage;
            $usageKnown = $usage->promptTokens > 0 || $usage->completionTokens > 0
                || $usage->cacheReadInputTokens > 0 || $usage->cacheWriteInputTokens > 0;

            // Preserve structured output because its text representation may be empty.
            $encodedStructured = $response instanceof StructuredAgentResponse
                ? json_encode($response->toArray(), JSON_UNESCAPED_UNICODE)
                : null;
            $structured = is_string($encodedStructured) ? $encodedStructured : null;

            // Diagnose empty structured responses: reasoning models can burn
            // the whole token budget thinking (finish_reason: length) and
            // return empty content. Recording why beats an opaque "[]".
            $responseMeta = [
                'layer_hashes' => $composed['metadata']['layer_hashes'],
                'guardrail_warnings' => $composed['metadata']['guardrail_warnings'],
                'prompt_version' => $this->getPromptVersion($agentKey),
            ];

            $emptyStructured = $structured !== null
                && ($response->text === '' || $response->text === '[]');

            if ($emptyStructured) {
                $responseMeta['empty_structured_output'] = true;
                $responseMeta['empty_output_hint'] =
                    'The model returned an empty structured response. If it is a reasoning model, '
                    .'raise the agent max_tokens so thinking plus the structured payload both fit '
                    .'(finish_reason: length consumes the budget before any content is emitted).';
            }

            $result = new AiExecutionResultData(
                runId: $run->id,
                agent: $agentKey,
                agentVersion: $agent->version(),
                provider: $providerName,
                model: $modelName,
                status: 'ok',
                reply: $structured ?? $response->text,
                structured: $structured !== null,
                usage: new AiUsageData(
                    inputTokens: $usageKnown ? $usage->promptTokens : null,
                    outputTokens: $usageKnown ? $usage->completionTokens : null,
                    totalTokens: $usageKnown ? $usage->promptTokens + $usage->completionTokens : null,
                    latencyMs: $latencyMs,
                    cachedTokens: $usageKnown ? $usage->cacheReadInputTokens : null,
                ),
                metadata: $responseMeta,
            );

            $run->refresh();
            if ($run->status !== 'cancelled') {
                $this->recorder->recordResult($run, $result, $providerPrivacy);
                $this->recorder->recordAttempt($run, 1, $providerName, $modelName, 'ok', $latencyMs);

                foreach ($response->toolResults as $toolResult) {
                    $this->recorder->recordToolCall($run, $toolResult->name, $toolResult->successful() ? 'ok' : 'error');
                }
            }

            return $result;
        } catch (\Exception $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            $run->refresh();
            if ($run->status !== 'cancelled') {
                $this->recorder->recordError($run, 'execution_failed');
                $this->recorder->recordAttempt($run, 1, $providerName, $modelName, 'error', $latencyMs, 'execution_failed');
            }

            throw new RuntimeException(
                "The AI agent [{$agentKey}] execution failed: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * Resolve the SDK Agent instance from the DomainAgent.
     *
     * @param  DomainAgent  $agent  The registered domain agent to resolve as an SDK agent.
     * @return Agent The SDK-compatible agent instance.
     *
     * @throws RuntimeException When the registered agent cannot be resolved as an SDK agent.
     */
    private function resolveSdkAgent(DomainAgent $agent): Agent
    {
        if ($agent instanceof Agent) {
            return $agent;
        }

        $class = get_class($agent);

        if (class_exists($class)) {
            return app($class);
        }

        throw new RuntimeException(
            "The AI agent [{$agent->key()}] cannot be resolved as an SDK agent.",
        );
    }

    /**
     * Get the prompt version from the ai_agents row.
     */
    private function getPromptVersion(string $agentKey): int
    {
        $row = AiAgent::findByKey($agentKey);

        return $row !== null ? $row->prompt_version : 0;
    }
}
