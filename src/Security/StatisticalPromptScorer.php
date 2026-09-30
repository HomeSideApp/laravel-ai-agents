<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

use HomeSide\AiAgents\Execution\AiExecutionContextData;

/**
 * Firewall layer 2: statistical scorer (language-agnostic).
 *
 * Produces evidence from text statistics that hold in any language:
 * character entropy, control-character ratios, unusual script mixing,
 * special-token density and repetition. Pure PHP, deterministic, no I/O,
 * no trained weights — every weight is visible in config.
 *
 * This layer does not rely on language semantics, so a prompt injection in
 * any language still produces structural evidence even when no lexicon
 * pattern matches.
 */
final class StatisticalPromptScorer implements PromptInspectorLayer
{
    /**
     * Signal weights (default) and per-signal activation.
     *
     * @var array<string, float>
     */
    private const DEFAULT_WEIGHTS = [
        'entropy' => 0.20,
        'control_chars' => 0.20,
        'script_mixing' => 0.15,
        'special_token_density' => 0.25,
        'repetition' => 0.20,
    ];

    /**
     * Shannon entropy of the character distribution above which the
     * signal starts contributing (bits per character). Normal prose sits
     * far below; base64/hex payloads sit far above.
     */
    private const ENTROPY_THRESHOLD = 5.2;

    /**
     * Maximum achievable entropy for byte alphabets (log2 of a generous
     * printable alphabet) used to normalise the signal to 0..1.
     */
    private const ENTROPY_CEILING = 8.0;

    public function __construct() {}

    public function key(): string
    {
        return 'scorer';
    }

    public function enabled(): bool
    {
        return (bool) config('ai-agents.firewall.scorer.enabled', true);
    }

    public function weight(): float
    {
        $weight = (float) config('ai-agents.firewall.scorer.weight', 0.3);

        return $weight > 0 ? $weight : 0.0;
    }

    /**
     * Score the content with every active statistical signal.
     */
    public function inspect(string $layer, string $content, AiExecutionContextData $context): Signal
    {
        if (! $this->enabled() || $content === '') {
            return Signal::none($this->key());
        }

        $perSignal = $this->signals($content);

        $totalWeight = 0.0;
        $weighted = 0.0;

        foreach ($perSignal as $name => $value) {
            $signalWeight = $this->signalWeight($name);

            if ($signalWeight <= 0.0) {
                continue;
            }

            $totalWeight += $signalWeight;
            $weighted += $signalWeight * $value;
        }

        $score = $totalWeight > 0.0 ? Signal::clamp($weighted / $totalWeight) : 0.0;

        if ($score <= 0.0) {
            return Signal::none($this->key());
        }

        $triggered = array_keys(array_filter(
            $perSignal,
            fn (float $value): bool => $value > 0.0,
        ));

        return new Signal(
            $this->key(),
            $score,
            $triggered,
            sprintf('signals: %s', implode(', ', array_map(
                fn (string $name, float $value): string => sprintf('%s=%.2f', $name, $value),
                array_keys($perSignal),
                array_values($perSignal),
            ))),
        );
    }

    /**
     * Compute every active signal, normalised to 0.0..1.0.
     *
     * @return array<string, float>
     */
    public function signals(string $content): array
    {
        return [
            'entropy' => $this->entropySignal($content),
            'control_chars' => $this->controlCharsSignal($content),
            'script_mixing' => $this->scriptMixingSignal($content),
            'special_token_density' => $this->specialTokenDensitySignal($content),
            'repetition' => $this->repetitionSignal($content),
        ];
    }

    /**
     * Shannon entropy normalised against the printable-byte ceiling and
     * thresholded so ordinary prose scores zero.
     */
    private function entropySignal(string $content): float
    {
        $length = mb_strlen($content);

        if ($length < 24) {
            // Too short for entropy to be meaningful.
            return 0.0;
        }

        $frequencies = [];

        foreach (mb_str_split($content) as $char) {
            $frequencies[$char] = ($frequencies[$char] ?? 0) + 1;
        }

        $entropy = 0.0;

        foreach ($frequencies as $count) {
            $probability = $count / $length;
            $entropy -= $probability * log($probability, 2);
        }

        if ($entropy <= self::ENTROPY_THRESHOLD) {
            return 0.0;
        }

        return Signal::clamp(
            ($entropy - self::ENTROPY_THRESHOLD) / (self::ENTROPY_CEILING - self::ENTROPY_THRESHOLD),
        );
    }

    /**
     * Ratio of control characters (excluding common whitespace).
     */
    private function controlCharsSignal(string $content): float
    {
        $length = strlen($content);

        if ($length === 0) {
            return 0.0;
        }

        $control = 0;

        foreach (str_split($content) as $byte) {
            $code = ord($byte);

            if ($code < 32 && ! in_array($code, [9, 10, 13], true)) {
                $control++;
            } elseif ($code === 127) {
                $control++;
            }
        }

        return Signal::clamp($control / $length * 4.0);
    }

    /**
     * Mixing of unrelated Unicode scripts inside one text — a common
     * homoglyph-evasion fingerprint.
     */
    private function scriptMixingSignal(string $content): float
    {
        $length = mb_strlen($content);

        if ($length < 12) {
            return 0.0;
        }

        $hasLatin = preg_match('/\p{Latin}/u', $content) === 1;
        $hasCyrillic = preg_match('/\p{Cyrillic}/u', $content) === 1;
        $hasGreek = preg_match('/\p{Greek}/u', $content) === 1;
        $hasHan = preg_match('/\p{Han}/u', $content) === 1;
        $hasArabic = preg_match('/\p{Arabic}/u', $content) === 1;

        $scripts = (int) $hasLatin + (int) $hasCyrillic + (int) $hasGreek + (int) $hasHan + (int) $hasArabic;

        if ($scripts < 2) {
            return 0.0;
        }

        return Signal::clamp(($scripts - 1) / 2.0);
    }

    /**
     * Density of role delimiters and special tokens from the structural
     * lexicon, normalised per 100 characters.
     */
    private function specialTokenDensitySignal(string $content): float
    {
        $matches = [];

        try {
            $matchCount = preg_match_all(
                '/(<\|[^|]{1,20}\|>|<<\s*\/?SYS\s*>>|\[\/?INST\]|\[\/?system\]|^\s*(system|assistant|developer)\s*:\s*$)/im',
                $content,
                $matches,
            );
        } catch (\Throwable) {
            return 0.0;
        }

        if ($matchCount === false || $matchCount === 0) {
            return 0.0;
        }

        $length = max(1, mb_strlen($content));

        return Signal::clamp($matchCount / $length * 100.0);
    }

    /**
     * Suspicious n-gram repetition: the same 4-gram appearing many times
     * suggests padding or obfuscation payload.
     */
    private function repetitionSignal(string $content): float
    {
        $length = mb_strlen($content);

        if ($length < 40) {
            return 0.0;
        }

        $compact = preg_replace('/\s+/u', ' ', $content);

        if (! is_string($compact) || mb_strlen($compact) < 40) {
            return 0.0;
        }

        $grams = [];
        $chars = mb_str_split($compact);
        $total = count($chars) - 3;

        for ($i = 0; $i < $total; $i++) {
            $gram = implode('', array_slice($chars, $i, 4));
            $grams[$gram] = ($grams[$gram] ?? 0) + 1;
        }

        $maxRepeated = 0;

        foreach ($grams as $count) {
            if ($count > $maxRepeated) {
                $maxRepeated = $count;
            }
        }

        if ($maxRepeated < 4) {
            return 0.0;
        }

        return Signal::clamp(($maxRepeated - 3) / 10.0);
    }

    /**
     * Effective weight for one signal, from config with defaults.
     */
    private function signalWeight(string $name): float
    {
        $weights = config('ai-agents.firewall.scorer.weights');

        if (! is_array($weights)) {
            return self::DEFAULT_WEIGHTS[$name] ?? 0.0;
        }

        $value = $weights[$name] ?? (self::DEFAULT_WEIGHTS[$name] ?? 0.0);

        return is_numeric($value) && $value > 0 ? (float) $value : 0.0;
    }
}
