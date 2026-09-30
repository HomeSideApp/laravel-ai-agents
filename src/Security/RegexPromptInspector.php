<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

use HomeSide\AiAgents\Contracts\InspectsPrompt;
use HomeSide\AiAgents\Contracts\ProvidesSanitisationPatterns;
use HomeSide\AiAgents\Execution\AiExecutionContextData;

/**
 * Default deterministic prompt inspector.
 *
 * Detects prompt injection attempts by matching Unicode NFKC-normalised
 * content against the regex patterns configured in
 * config('ai-agents.injection_patterns'). NFKC normalisation collapses
 * homoglyph and full-width tricks that bypass naive pattern matching.
 *
 * The action is decided per the package firewall config ('flag' by default;
 * 'block' is opt-in) so false positives never silently break a host's users.
 */
final class RegexPromptInspector implements InspectsPrompt, ProvidesSanitisationPatterns
{
    /**
     * Normalisation strength: NFKC folds compatibility characters (e.g.
     * full-width 'ｉｇｎｏｒｅ', circled letters, some homoglyphs) into
     * their canonical forms before matching.
     */
    private const NORMALISATION = \Normalizer::NFKC;

    /**
     * Inspect a prompt layer against the configured injection patterns.
     *
     * The content is NFKC-normalised before matching to neutralise
     * homoglyph/full-width evasion. The action in the returned finding is
     * taken from config('ai-agents.firewall.action'), falling back to
     * ACTION_FLAG for unknown values so an invalid host config can never
     * silently turn flagging into blocking.
     *
     * @param  string  $layer  The prompt layer being inspected (e.g. 'user',
     *                         'context', 'user_message') — used by callers for
     *                         logging and metadata attribution.
     * @param  string  $content  The raw text of the layer to scan.
     * @param  AiExecutionContextData  $context  The execution context; unused
     *                                           by this implementation but kept
     *                                           for interface parity (custom
     *                                           inspectors may personalise rules).
     * @return FirewallFinding ACTION_ALLOW when clean; otherwise the configured
     *                         action with the matched pattern identifiers and a
     *                         100-character content snippet as note.
     */
    public function inspect(string $layer, string $content, AiExecutionContextData $context): FirewallFinding
    {
        $matches = $this->matches($content);

        if ($matches === []) {
            return FirewallFinding::clean();
        }

        $action = (string) config('ai-agents.firewall.action', FirewallFinding::ACTION_FLAG);

        if (! in_array($action, [FirewallFinding::ACTION_ALLOW, FirewallFinding::ACTION_FLAG, FirewallFinding::ACTION_BLOCK], true)) {
            $action = FirewallFinding::ACTION_FLAG;
        }

        return new FirewallFinding(
            action: $action,
            patterns: $matches,
            note: substr($content, 0, 100),
        );
    }

    /**
     * Return the pattern identifiers that match the normalised content.
     *
     * Runs every configured pattern against the NFKC-normalised content,
     * skipping (never throwing on) patterns the host configured invalidly.
     *
     * @param  string  $content  The raw text to scan.
     * @return list<string> Pattern labels (when the host configured
     *                      'regex' => 'label' pairs) or the raw regexes for
     *                      unlabelled entries; empty when nothing matches.
     */
    public function matches(string $content): array
    {
        $normalised = normalizer_normalize($content, self::NORMALISATION);

        if ($normalised === false) {
            $normalised = $content;
        }

        $matched = [];

        foreach ($this->patterns() as $key => $pattern) {
            try {
                if (preg_match($pattern, $normalised) === 1) {
                    $matched[] = $key;
                }
            } catch (\Throwable) {
                // An invalid host-supplied regex must never break execution.
                continue;
            }
        }

        return $matched;
    }

    /**
     * Expose the configured patterns (label/regex map) for reuse by the
     * prompt compositor and admin UIs.
     *
     * @return array<string, string> Map of pattern identifier → regex, where
     *                               the identifier is the host-provided label
     *                               or the regex itself for unlabelled entries.
     */
    public function patterns(): array
    {
        return $this->patternsFromConfig();
    }

    /**
     * Flat regex list for the compositor's sanitisation pass.
     *
     * @return list<string>
     */
    public function patternsForSanitisation(): array
    {
        return array_values($this->patterns());
    }

    /**
     * Read and normalise the injection patterns from config.
     *
     * Accepts both plain regex strings and 'regex' => 'label' pairs; entries
     * that are not non-empty strings are discarded so a malformed host config
     * degrades to fewer patterns instead of runtime errors.
     *
     * @return array<string, string> Map of identifier → regex (possibly empty).
     */
    private function patternsFromConfig(): array
    {
        $patterns = config('ai-agents.injection_patterns');

        if (! is_array($patterns)) {
            return [];
        }

        $result = [];

        foreach ($patterns as $key => $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $result[is_string($key) && $key !== '' ? $key : $pattern] = $pattern;
        }

        return $result;
    }
}
