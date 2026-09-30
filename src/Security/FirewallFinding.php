<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

/**
 * Result of a prompt inspection performed by an InspectsPrompt implementation.
 *
 * @readonly
 */
final class FirewallFinding
{
    public const ACTION_ALLOW = 'allow';

    public const ACTION_FLAG = 'flag';

    public const ACTION_BLOCK = 'block';

    /**
     * Construct a firewall finding.
     *
     * @param  string  $action  The resolved action: one of the ACTION_* constants
     *                          ('allow', 'flag' or 'block') as decided by the
     *                          inspector implementation.
     * @param  list<string>  $patterns  Human-readable identifiers of the matched
     *                                  detections (pattern labels when the host
     *                                  provides them, otherwise the raw regexes).
     * @param  string|null  $note  Optional implementation-specific detail, e.g. a
     *                             truncated snippet of the inspected content.
     * @param  float  $score  Aggregated evidence score between 0.0 and 1.0;
     *                        0.0 for single-layer inspectors that do not score.
     * @param  array<string, float>  $signals  Per-layer contribution map
     *                                         (layer key => score), empty for
     *                                         single-layer inspectors.
     */
    public function __construct(
        public readonly string $action,
        public readonly array $patterns = [],
        public readonly ?string $note = null,
        public readonly float $score = 0.0,
        public readonly array $signals = [],
    ) {}

    /**
     * Determine whether the inspected content may proceed unmodified.
     *
     * @return bool True when the action is ACTION_ALLOW.
     */
    public function allows(): bool
    {
        return $this->action === self::ACTION_ALLOW;
    }

    /**
     * Determine whether the inspected content must abort the agent run.
     *
     * @return bool True when the action is ACTION_BLOCK; the caller is
     *              expected to raise PromptInjectionBlockedException.
     */
    public function blocks(): bool
    {
        return $this->action === self::ACTION_BLOCK;
    }

    /**
     * Determine whether the finding should be recorded but not abort the run.
     *
     * @return bool True when the action is ACTION_FLAG; the caller records the
     *              finding in run metadata and logs while continuing.
     */
    public function flags(): bool
    {
        return $this->action === self::ACTION_FLAG;
    }

    /**
     * Create the finding returned when inspection found no injection attempt.
     *
     * @return self A finding with ACTION_ALLOW, no patterns and no note.
     */
    public static function clean(): self
    {
        return new self(self::ACTION_ALLOW);
    }
}
