<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Enums;

/**
 * How the free-text content (user_message, reply) of a run is stored.
 *
 * Decided at write time by the retention resolver: the host's ceiling
 * (config('ai-agents.logging.retention')) always wins; inside that ceiling
 * the user's consent decides between plain and encrypted storage.
 *
 * - plain:     text as-is (user consented to sharing).
 * - encrypted: reversible envelope encryption with the user's data key
 *              (default) — support grants authorise on-the-fly decryption.
 * - redacted:  deterministic digest — correlatable, never readable.
 * - none:      nothing stored.
 */
enum ContentMode: string
{
    case Plain = 'plain';
    case Encrypted = 'encrypted';
    case Redacted = 'redacted';
    case None = 'none';

    /**
     * Build the mode from a stored column value, defaulting to plain
     * (legacy rows predate the column and hold whatever the old policy
     * kept — never crash on them).
     */
    public static function fromColumn(?string $value): self
    {
        return $value !== null ? (self::tryFrom($value) ?? self::Plain) : self::Plain;
    }

    /**
     * Whether a run stored with this mode can ever expose readable text.
     */
    public function isReadable(): bool
    {
        return $this === self::Plain || $this === self::Encrypted;
    }
}
