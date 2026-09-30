<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Security\FirewallFinding;

/**
 * Contract for prompt inspection (firewall) implementations.
 *
 * The package ships RegexPromptInspector as the default: a deterministic,
 * config-driven pattern matcher hardened with Unicode NFKC normalisation.
 * Hosts needing semantic detection, ML classifiers or an external firewall
 * service implement this contract and register the class-string in
 * config('ai-agents.firewall.inspector').
 *
 * No firewall guarantees total protection. This layer complements — never
 * replaces — the structural defences: prompt hierarchy enforcement and
 * server-side authorisation inside every tool.
 */
interface InspectsPrompt
{
    /**
     * Inspect a prompt layer before it reaches the model.
     *
     * @param  string  $layer  One of: guardrail, global_policy, platform, user, context, runtime, user_message.
     * @param  string  $content  The raw layer content.
     * @param  AiExecutionContextData  $context  The execution context.
     */
    public function inspect(string $layer, string $content, AiExecutionContextData $context): FirewallFinding;
}
