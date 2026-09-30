<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

use HomeSide\AiAgents\Contracts\InspectsPrompt;
use HomeSide\AiAgents\Execution\AiExecutionContextData;

/**
 * No-op prompt inspector used when the firewall is disabled.
 *
 * Bound by the service provider when config('ai-agents.firewall.enabled')
 * is false (and no custom inspector is configured), so consumers can call
 * InspectsPrompt unconditionally — there is never a null inspector and no
 * scattered config checks at the call sites. Every inspection yields a
 * clean finding: the null object IS the "firewall off" behaviour.
 */
final class NullPromptInspector implements InspectsPrompt
{
    /**
     * Report the inspected content as clean, unconditionally.
     *
     * @param  string  $layer  Ignored; kept for interface parity.
     * @param  string  $content  Ignored; nothing is scanned.
     * @param  AiExecutionContextData  $context  Ignored; kept for interface
     *                                           parity with custom inspectors.
     * @return FirewallFinding Always a clean (ACTION_ALLOW) finding, so
     *                         callers proceed exactly as they would with a
     *                         passing regex inspection.
     */
    public function inspect(string $layer, string $content, AiExecutionContextData $context): FirewallFinding
    {
        return FirewallFinding::clean();
    }
}
