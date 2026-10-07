<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Configuration;

use HomeSide\AiAgents\Enums\AiDriver;
use HomeSide\AiAgents\Enums\ToolCallFormat;

/**
 * Declared capabilities and quirks of the model behind a provider.
 *
 * Sourced from ai_providers.configuration.model_capabilities (admin-set) —
 * NOT inferred — with per-driver defaults as the baseline. Agents and the
 * manager use this to adapt requests instead of guessing: a reasoning model
 * needs a bigger max_tokens budget (thinking consumes it before content);
 * XML tool-calling models need the matching format hint; and models that do
 * not expose a provider tool get that tool skipped before the request.
 *
 * @phpstan-type CapabilitiesArray array{
 *     reasoning?: bool,
 *     reasoning_effort?: string|null,
 *     tool_call_format?: string|null,
 *     context_tokens?: int|null,
 *     provider_tools?: list<string>,
 * }
 */
final readonly class ModelCapabilities
{
    /**
     * @param  bool  $reasoning  Whether the model reasons before answering
     *                           (burns tokens from the same max_tokens budget).
     * @param  string|null  $reasoningEffort  Provider-specific effort knob
     *                                        ('low'|'medium'|'high'|...).
     * @param  ToolCallFormat|null  $toolCallFormat  Wire format the model
     *                                               expects for tools.
     * @param  int|null  $contextTokens  Advertised context window (1M, 262K...).
     * @param  list<Capability>  $providerTools  Provider (built-in) tools the
     *                                           model can run server-side.
     */
    public function __construct(
        public bool $reasoning = false,
        public ?string $reasoningEffort = null,
        public ?ToolCallFormat $toolCallFormat = null,
        public ?int $contextTokens = null,
        public array $providerTools = [],
    ) {}

    /**
     * Build from the provider's configuration array, falling back to the
     * driver baseline for undeclared keys.
     *
     * @param  array<string, mixed>|null  $configuration  The ai_providers.configuration JSON.
     */
    public static function fromProviderConfiguration(?array $configuration, AiDriver $driver): self
    {
        $base = $driver->defaultCapabilities();

        /** @var CapabilitiesArray $declared */
        $declared = is_array($configuration['model_capabilities'] ?? null)
            ? $configuration['model_capabilities']
            : [];

        $toolCallFormat = $declared['tool_call_format']
            ?? $base['tool_call_format'] ?? null;

        $declaredTools = $declared['provider_tools'] ?? null;

        return new self(
            reasoning: (bool) ($declared['reasoning'] ?? $base['reasoning'] ?? false),
            reasoningEffort: isset($declared['reasoning_effort']) ? (string) $declared['reasoning_effort'] : null,
            toolCallFormat: $toolCallFormat !== null ? (ToolCallFormat::tryFrom((string) $toolCallFormat) ?? null) : null,
            contextTokens: isset($declared['context_tokens']) ? (int) $declared['context_tokens'] : null,
            providerTools: is_array($declaredTools)
                ? self::normaliseProviderTools($declaredTools)
                : self::baselineProviderTools($driver),
        );
    }

    /**
     * Whether the token budget must cover reasoning before content.
     */
    public function consumesThinkingTokens(): bool
    {
        return $this->reasoning;
    }

    /**
     * Whether the model can run the given provider-tool capability.
     *
     * @param  Capability  $capability  The provider-tool capability to check.
     */
    public function supportsProviderTool(Capability $capability): bool
    {
        return in_array($capability, $this->providerTools, true);
    }

    /**
     * The provider-tool capabilities advertised by the driver baseline.
     *
     * @return list<Capability>
     */
    private static function baselineProviderTools(AiDriver $driver): array
    {
        return array_values(array_filter(
            Capability::driverBaseline($driver->value),
            static fn (Capability $capability): bool => in_array(
                $capability,
                Capability::providerToolMap(),
                true,
            ),
        ));
    }

    /**
     * Narrow a declared list of capability value strings to Capability cases.
     *
     * @param  list<string>  $values  The declared provider-tool values.
     * @return list<Capability>
     */
    private static function normaliseProviderTools(array $values): array
    {
        $tools = [];

        foreach ($values as $value) {
            $capability = Capability::tryFrom((string) $value);

            if ($capability !== null && in_array($capability, Capability::providerToolMap(), true)) {
                $tools[] = $capability;
            }
        }

        return $tools;
    }
}
