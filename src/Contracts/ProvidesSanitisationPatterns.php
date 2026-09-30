<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

/**
 * Optional contract for inspectors that can supply regex patterns for the
 * prompt compositor's sanitisation pass.
 *
 * RegexPromptInspector and the firewall's lexicon layer implement this so
 * PromptCompositor can replace matched text with '[SECURITY RESTRICTION]'
 * using exactly the patterns that produced the finding — detection and
 * sanitisation can never drift apart.
 */
interface ProvidesSanitisationPatterns
{
    /**
     * All active patterns, as a flat list of preg_* regex strings.
     *
     * @return list<string>
     */
    public function patternsForSanitisation(): array;
}
