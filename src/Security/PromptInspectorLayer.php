<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

use HomeSide\AiAgents\Execution\AiExecutionContextData;

/**
 * Contract for one layer of the prompt firewall pipeline.
 *
 * Each layer is an independent, toggleable detector producing a Signal.
 * The PromptFirewallPipeline aggregates layer signals into a single
 * FirewallFinding decision, so no single layer can silently dominate the
 * outcome. Layers intentionally do not extend InspectsPrompt: they return
 * evidence (Signal), not decisions (FirewallFinding).
 */
interface PromptInspectorLayer
{
    /**
     * The layer identifier used in findings metadata
     * (e.g. 'lexicon', 'scorer', 'classifier').
     */
    public function key(): string;

    /**
     * Whether the layer participates in the pipeline.
     *
     * Read from config on every call so tests and hosts can toggle a layer
     * without rebuilding the container.
     */
    public function enabled(): bool;

    /**
     * The layer's weight in the aggregated score.
     */
    public function weight(): float;

    /**
     * Produce evidence for one prompt layer.
     *
     * @param  string  $layer  The prompt layer being inspected (e.g. 'user').
     * @param  string  $content  The raw text of the layer to scan.
     * @param  AiExecutionContextData  $context  The execution context.
     */
    public function inspect(string $layer, string $content, AiExecutionContextData $context): Signal;
}
