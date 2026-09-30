<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Execution;

use HomeSide\AiAgents\Enums\ContentMode;
use HomeSide\AiAgents\Enums\PrivacyLevel;

/**
 * Decides how the free-text fields of an AI run (user_message, reply) are
 * stored, and applies that decision.
 *
 * Two policies compose, and the most restrictive always wins:
 *
 * 1. The HOST CEILING: config('ai-agents.logging.retention') maps each
 *    provider privacy level to full / redacted / none. A 'none' ceiling
 *    means nothing is stored no matter what the user consents to.
 * 2. The USER CONSENT (via ContentMode from the retention resolver):
 *    without consent the default is encrypted (reversible, key per user);
 *    with consent the text is stored as-is.
 *
 * Modes returned by resolve():
 *
 * - plain:     store the text as-is (consented user, permissive ceiling).
 * - encrypted: reversible envelope encryption with the user's data key
 *              (default for non-consented users).
 * - redacted:  deterministic digest (sha256 prefix + length) — runs stay
 *              correlatable without exposing the conversation.
 * - none:      store null.
 */
final class RunContentRedactor
{
    private const MODES = ['full', 'redacted', 'none', 'encrypted'];

    private const DEFAULT_DIGEST_LENGTH = 12;

    /**
     * Resolve the effective ContentMode for a run.
     *
     * @param  PrivacyLevel  $level  The privacy level of the resolved provider.
     * @param  bool  $userConsented  Whether the user consented to storing
     *                               readable content.
     */
    public function resolveMode(PrivacyLevel $level, bool $userConsented = false): ContentMode
    {
        $ceiling = $this->ceilingFor($level);

        // The host's ceiling always wins — consent can never lift it.
        if ($ceiling === 'none') {
            return ContentMode::None;
        }

        if ($ceiling === 'redacted') {
            return ContentMode::Redacted;
        }

        // Ceiling is permissive ('full' or 'encrypted'): consent decides.
        return $userConsented ? ContentMode::Plain : ContentMode::Encrypted;
    }

    /**
     * Apply the retention policy for the provider's privacy level
     * (backwards-compatible API: assumes the permissive legacy behaviour
     * of mapping the host's ceiling directly).
     *
     * @param  string|null  $text  The raw free-text content (message or reply).
     * @param  PrivacyLevel  $level  The privacy level of the resolved provider.
     */
    public function retain(?string $text, PrivacyLevel $level): ?string
    {
        return match ($this->ceilingFor($level)) {
            'redacted' => $text === null ? null : $this->digest($text),
            'none' => null,
            default => $text,
        };
    }

    /**
     * Apply a resolved ContentMode to a raw text value, returning what the
     * model must persist.
     *
     * Encrypted passes the RAW text through: the AiRun content cast owns
     * the envelope encryption (key per user) and is driven by the row's
     * content_mode attribute, so nothing is double-encrypted here.
     *
     * @param  string|null  $text  The raw free-text content.
     * @param  ContentMode  $mode  The resolved content mode.
     */
    public function retainWithMode(?string $text, ContentMode $mode): ?string
    {
        return match ($mode) {
            ContentMode::Redacted => $text === null ? null : $this->digest($text),
            ContentMode::None => null,
            ContentMode::Encrypted, ContentMode::Plain => $text,
        };
    }

    /**
     * The configured retention ceiling for one privacy level.
     *
     * 'encrypted' is also accepted as a host ceiling (meaning: never store
     * plaintext for this level, consent or not).
     */
    private function ceilingFor(PrivacyLevel $level): string
    {
        $retention = (array) config('ai-agents.logging.retention', []);
        $mode = $retention[$level->value] ?? 'full';

        return in_array($mode, self::MODES, true) ? $mode : 'full';
    }

    /**
     * Build the deterministic digest stored in place of the raw text.
     */
    private function digest(string $text): string
    {
        $configured = (int) config('ai-agents.logging.redacted_digest_length', self::DEFAULT_DIGEST_LENGTH);
        $length = $configured > 0 && $configured <= 64 ? $configured : self::DEFAULT_DIGEST_LENGTH;

        return sprintf(
            '[redacted sha256:%s len=%d]',
            substr(hash('sha256', $text), 0, $length),
            mb_strlen($text),
        );
    }
}
