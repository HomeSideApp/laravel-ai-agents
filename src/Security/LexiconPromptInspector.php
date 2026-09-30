<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

use HomeSide\AiAgents\Contracts\ProvidesSanitisationPatterns;
use HomeSide\AiAgents\Execution\AiExecutionContextData;

/**
 * Firewall layer 1: multilingual lexicon matcher.
 *
 * Extends the original single-list RegexPromptInspector approach with
 * per-language pattern files and a language-independent structural file.
 * Content is NFKC-normalised before matching, exactly as before, so
 * homoglyph and full-width evasion stays neutralised.
 *
 * The structural file is always loaded — role delimiters and scaffolding
 * markers are not language-dependent and are the strongest deterministic
 * evidence available. Language files are opt-in through
 * config('ai-agents.firewall.lexicon.languages').
 */
final class LexiconPromptInspector implements PromptInspectorLayer, ProvidesSanitisationPatterns
{
    private const NORMALISATION = \Normalizer::NFKC;

    /**
     * Loaded pattern cache keyed by source file (per-process).
     *
     * @var array<string, array<string, string>>
     */
    private array $cache = [];

    public function __construct(
        private readonly string $lexiconPath,
    ) {}

    public function key(): string
    {
        return 'lexicon';
    }

    public function enabled(): bool
    {
        return (bool) config('ai-agents.firewall.lexicon.enabled', true);
    }

    public function weight(): float
    {
        $weight = (float) config('ai-agents.firewall.lexicon.weight', 0.5);

        return $weight > 0 ? $weight : 0.0;
    }

    /**
     * Inspect content against the structural and configured language files.
     */
    public function inspect(string $layer, string $content, AiExecutionContextData $context): Signal
    {
        if (! $this->enabled()) {
            return Signal::none($this->key());
        }

        $matches = $this->matches($content);

        if ($matches === []) {
            return Signal::none($this->key());
        }

        // The structural category is a hard signal: scoring it at the
        // maximum reflects that role delimiters never occur in legitimate
        // user text.
        $score = Signal::clamp(count($matches) > 0 ? 1.0 : 0.0);

        return new Signal($this->key(), $score, $matches, mb_substr($content, 0, 100));
    }

    /**
     * Return the pattern identifiers matching the normalised content.
     *
     * @return list<string> Matched labels (or raw regexes for unlabelled
     *                      entries); empty when nothing matches.
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
                    $matched[] = $this->label($key);
                }
            } catch (\Throwable) {
                // An invalid pattern must never break execution.
                continue;
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * Collapse a composite pattern key onto its label.
     *
     * Keys look like 'label', 'label#2' (sibling regex in one file) or
     * 'en#label#2' (language-discriminated). The trailing '#n' sibling
     * suffix and the leading language discriminator are stripped so all
     * variants report the same label.
     */
    private function label(string $key): string
    {
        $key = (string) preg_replace('/#\d+$/', '', $key);

        $position = strpos($key, '#');

        return $position === false ? $key : substr($key, $position + 1);
    }

    /**
     * All active patterns (structural first, then configured languages,
     * then the host's injected extras).
     *
     * Keys are made unique per source file by prefixing a file discriminator
     * so same-named labels across languages do not silently overwrite each
     * other; label() strips everything up to the last '#' separator.
     *
     * @return array<string, string>
     */
    public function patterns(): array
    {
        $patterns = $this->loadFile('structural');

        foreach ($this->languages() as $language) {
            foreach ($this->loadFile($language) as $key => $pattern) {
                $patterns[$language.'#'.$key] = $pattern;
            }
        }

        foreach ($this->hostExtras() as $key => $pattern) {
            $patterns[$key] = $pattern;
        }

        return $patterns;
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
     * Configured language codes (structural is handled separately and
     * cannot be disabled).
     *
     * @return list<string>
     */
    private function languages(): array
    {
        $languages = config('ai-agents.firewall.lexicon.languages', ['en', 'es']);

        if (! is_array($languages)) {
            return [];
        }

        $valid = [];

        foreach ($languages as $language) {
            if (is_string($language) && preg_match('/^[a-z]{2}$/', $language) === 1) {
                $valid[] = $language;
            }
        }

        return $valid;
    }

    /**
     * Host-injected extra patterns from config('ai-agents.injection_patterns'),
     * preserving the legacy configuration surface.
     *
     * @return array<string, string>
     */
    private function hostExtras(): array
    {
        $extras = config('ai-agents.injection_patterns');

        if (! is_array($extras)) {
            return [];
        }

        $result = [];

        foreach ($extras as $key => $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $result[is_string($key) && $key !== '' ? $key : $pattern] = $pattern;
        }

        return $result;
    }

    /**
     * Load one lexicon file with per-process caching; missing files yield
     * no patterns (a host may configure a language we do not ship).
     *
     * @return array<string, string>
     */
    private function loadFile(string $name): array
    {
        if (array_key_exists($name, $this->cache)) {
            return $this->cache[$name];
        }

        $path = $this->lexiconPath.'/'.$name.'.php';

        /** @var mixed $patterns */
        $patterns = is_file($path) ? require $path : [];
        $normalised = [];

        if (is_array($patterns)) {
            foreach ($patterns as $key => $pattern) {
                if (! is_string($pattern) || $pattern === '') {
                    continue;
                }

                $normalised[is_string($key) && $key !== '' ? $key : $pattern] = $pattern;
            }
        }

        return $this->cache[$name] = $normalised;
    }
}
