<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

/**
 * Result of one firewall layer's inspection.
 *
 * Layers produce signals; the pipeline aggregates them into a single
 * FirewallFinding. A signal carries a normalised score (0.0 = no evidence,
 * 1.0 = certainty), the human-readable identifiers of what matched and an
 * optional note for observability.
 *
 * @readonly
 */
final class Signal
{
    /**
     * Construct a layer signal.
     *
     * @param  string  $layerKey  The layer identifier ('lexicon', 'scorer', ...).
     * @param  float  $score  Evidence strength between 0.0 and 1.0.
     * @param  list<string>  $matches  Identifiers of the matched detections.
     * @param  string|null  $note  Optional implementation-specific detail.
     */
    public function __construct(
        public readonly string $layerKey,
        public readonly float $score,
        public readonly array $matches = [],
        public readonly ?string $note = null,
    ) {}

    /**
     * Clamp a raw score into the valid 0.0..1.0 range.
     */
    public static function clamp(float $score): float
    {
        return max(0.0, min(1.0, $score));
    }

    /**
     * A no-evidence signal for the given layer.
     */
    public static function none(string $layerKey): self
    {
        return new self($layerKey, 0.0);
    }
}
