<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

use HomeSide\AiAgents\Contracts\InspectsPrompt;
use HomeSide\AiAgents\Contracts\ProvidesSanitisationPatterns;
use HomeSide\AiAgents\Execution\AiExecutionContextData;

/**
 * Aggregating prompt firewall.
 *
 * Implements InspectsPrompt by running every registered PromptInspectorLayer,
 * collecting Signals and combining them into one decision:
 *
 * - The layer scores are combined as a weighted mean (weights in config).
 * - score >= firewall.thresholds.block  => ACTION_BLOCK
 * - score >= firewall.thresholds.flag   => ACTION_FLAG
 * - otherwise                           => ACTION_ALLOW
 *
 * Overrides:
 * - A 'role_delimiter' match in the lexicon layer forces ACTION_BLOCK when
 *   firewall.lexicon.block_structural is true: role delimiters never occur
 *   legitimately in user text.
 * - When firewall.allow_block_from_score is false (default), a high score
 *   never escalates the configured global action — the pipeline only
 *   hardens to BLOCK if the host opted in, or if the structural override
 *   applies. This preserves the package's "flag by default, block opt-in"
 *   safety stance.
 *
 * The pipeline is deterministic and never throws: a layer that fails is
 * treated as producing no evidence.
 */
final class PromptFirewallPipeline implements InspectsPrompt, ProvidesSanitisationPatterns
{
    /**
     * @param  list<PromptInspectorLayer>  $layers  Ordered layer detectors.
     */
    public function __construct(
        private readonly array $layers,
    ) {}

    /**
     * Flat regex list for the compositor's sanitisation pass: the union of
     * every enabled layer that provides patterns (currently the lexicon
     * layer). Falls back to an empty list when no layer provides any.
     *
     * @return list<string>
     */
    public function patternsForSanitisation(): array
    {
        $patterns = [];

        foreach ($this->layers as $layer) {
            if (! $layer instanceof ProvidesSanitisationPatterns || ! $layer->enabled()) {
                continue;
            }

            foreach ($layer->patternsForSanitisation() as $pattern) {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * Run every enabled layer and aggregate into one finding.
     */
    public function inspect(string $layer, string $content, AiExecutionContextData $context): FirewallFinding
    {
        $signals = [];
        $patterns = [];
        $notes = [];
        $structuralMatch = false;

        foreach ($this->layers as $candidate) {
            if (! $candidate->enabled()) {
                continue;
            }

            try {
                $signal = $candidate->inspect($layer, $content, $context);
            } catch (\Throwable) {
                // A failing layer is evidence of nothing, never an error
                // for the host request.
                continue;
            }

            $signals[$candidate->key()] = $signal->score;

            if ($signal->matches !== []) {
                foreach ($signal->matches as $match) {
                    $patterns[] = $match;

                    if ($match === 'role_delimiter') {
                        $structuralMatch = true;
                    }
                }
            }

            if ($signal->note !== null) {
                $notes[] = $candidate->key().': '.$signal->note;
            }
        }

        $score = $this->aggregate($signals);

        $action = $this->decide($score, $structuralMatch);

        if ($action === FirewallFinding::ACTION_ALLOW) {
            return FirewallFinding::clean();
        }

        return new FirewallFinding(
            action: $action,
            patterns: array_values(array_unique($patterns)),
            note: implode(' | ', array_slice($notes, 0, 3)),
            score: $score,
            signals: $signals,
        );
    }

    /**
     * Weighted mean of the layer scores; 0.0 when no layer contributed.
     *
     * @param  array<string, float>  $signals
     */
    private function aggregate(array $signals): float
    {
        $totalWeight = 0.0;
        $weighted = 0.0;

        foreach ($this->layers as $layer) {
            if (! $layer->enabled() || ! array_key_exists($layer->key(), $signals)) {
                continue;
            }

            $weight = $layer->weight();

            if ($weight <= 0.0) {
                continue;
            }

            $totalWeight += $weight;
            $weighted += $weight * $signals[$layer->key()];
        }

        return $totalWeight > 0.0 ? Signal::clamp($weighted / $totalWeight) : 0.0;
    }

    /**
     * Resolve the action from the score and the structural override.
     */
    private function decide(float $score, bool $structuralMatch): string
    {
        $configured = (string) config('ai-agents.firewall.action', FirewallFinding::ACTION_FLAG);

        if (! in_array($configured, [FirewallFinding::ACTION_ALLOW, FirewallFinding::ACTION_FLAG, FirewallFinding::ACTION_BLOCK], true)) {
            $configured = FirewallFinding::ACTION_FLAG;
        }

        $scoreCanBlock = $configured === FirewallFinding::ACTION_BLOCK
            || (bool) config('ai-agents.firewall.allow_block_from_score', false);

        $blockAt = (float) config('ai-agents.firewall.thresholds.block', 0.80);
        $flagAt = (float) config('ai-agents.firewall.thresholds.flag', 0.35);

        // Score-driven escalation (block threshold or configured block
        // action).
        if ($scoreCanBlock && $score >= $blockAt) {
            return FirewallFinding::ACTION_BLOCK;
        }

        // Structural override: role delimiters never occur in legitimate
        // user text, so they block whenever the host has not explicitly
        // disabled the override — independently of the score stance.
        if ($structuralMatch
            && (bool) config('ai-agents.firewall.lexicon.block_structural', true)) {
            return FirewallFinding::ACTION_BLOCK;
        }

        if ($configured === FirewallFinding::ACTION_ALLOW) {
            // A host that configured 'allow' still gets flag-level
            // observability from the score, but never blocking.
            return $score >= $flagAt ? FirewallFinding::ACTION_FLAG : FirewallFinding::ACTION_ALLOW;
        }

        if ($score >= $flagAt) {
            return $configured;
        }

        return FirewallFinding::ACTION_ALLOW;
    }
}
