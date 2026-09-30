<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Prompting;

use HomeSide\AiAgents\Contracts\DomainAgent;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use Laravel\Ai\Contracts\Agent;

/**
 * Builds multi-layer prompts for agents.
 *
 * Layers (in order):
 * 1. Core agent instructions (from the agent, not editable)
 * 2. Installation (global admin)
 * 3. Module (per-module admin)
 * 4. Tenant (tenant instructions)
 * 5. User (user instructions)
 * 6. Domain context (ContextBuilder data)
 * 7. Runtime context (execution data: locale, timezone, etc.)
 * 8. User message (user prompt)
 *
 * No editable layer replaces Core.
 */
class PromptBuilder
{
    /**
     * Build the composite system prompt from all layers.
     *
     * @param  DomainAgent  $agent  The agent instance.
     * @param  string  $userMessage  The user prompt (layer 8).
     * @param  array<string, mixed>  $domainContext  The ContextBuilder data (layer 6).
     * @param  array{installation?: string, module?: string, tenant?: string, user?: string}  $instructions  Per-layer instructions (2-5).
     * @param  AiExecutionContextData|null  $executionContext  The execution context (layer 7).
     * @return array{system: string, user: string}
     */
    public function build(
        DomainAgent $agent,
        string $userMessage,
        array $domainContext = [],
        array $instructions = [],
        ?AiExecutionContextData $executionContext = null,
    ): array {
        $layers = [];

        // 1. Core agent instructions (from the agent, not editable).
        // Uses the SDK native Agent::instructions() interface.
        if ($agent instanceof Agent) {
            $coreInstructions = (string) $agent->instructions();
        } else {
            $coreInstructions = '';
        }

        if ($coreInstructions !== '') {
            $layers[] = $coreInstructions;
        }

        // 2. Installation (global admin).
        if (! empty($instructions['installation'])) {
            $layers[] = $instructions['installation'];
        }

        // 3. Module (per-module admin).
        if (! empty($instructions['module'])) {
            $layers[] = $instructions['module'];
        }

        // 4. Tenant.
        if (! empty($instructions['tenant'])) {
            $layers[] = $instructions['tenant'];
        }

        // 5. User.
        if (! empty($instructions['user'])) {
            $layers[] = $instructions['user'];
        }

        // 6. Domain context (from ContextBuilder).
        if ($domainContext !== []) {
            $contextBlock = $this->formatDomainContext($domainContext);
            if ($contextBlock !== '') {
                $layers[] = "## Domain context\n\n{$contextBlock}";
            }
        }

        // 7. Runtime context (locale, timezone, etc.).
        if ($executionContext !== null) {
            $runtimeBlock = $this->formatRuntimeContext($executionContext);
            if ($runtimeBlock !== '') {
                $layers[] = "## Execution context\n\n{$runtimeBlock}";
            }
        }

        $system = implode("\n\n", $layers);

        return [
            'system' => $system,
            'user' => $userMessage,
        ];
    }

    /**
     * Format the domain context as a readable text block.
     *
     * @param  array<string, mixed>  $context
     */
    private function formatDomainContext(array $context): string
    {
        $parts = [];

        foreach ($context as $key => $data) {
            if (is_array($data) || is_object($data)) {
                $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $parts[] = "### {$key}\n\n{$json}";
            } else {
                $parts[] = "### {$key}\n\n{$data}";
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Format the runtime context.
     */
    private function formatRuntimeContext(AiExecutionContextData $context): string
    {
        $parts = [];

        $parts[] = "- Locale: {$context->locale}";
        $parts[] = "- Timezone: {$context->timezone}";

        return implode("\n", $parts);
    }
}
