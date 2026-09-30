<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Enums;

/**
 * Privacy level of an AI provider.
 *
 * Classifies where the model runs, driving fallback and data-governance
 * decisions. Values match the host-facing contract; the provider resolution
 * compares candidate privacy levels against the chosen provider's
 * fallback policy (see FallbackPolicy::accepts()).
 */
enum PrivacyLevel: string
{
    /** Model running on the local network (Ollama, vLLM, etc.). */
    case Local = 'local';

    /** Model running on a self-hosted server (not public cloud). */
    case SelfHosted = 'self_hosted';

    /** Model running on a public cloud service (OpenAI, Anthropic, etc.). */
    case Cloud = 'cloud';

    /** Not classified (custom or generic providers). */
    case Unknown = 'unknown';

    /**
     * Semantic privacy order: local is the most private, cloud the least,
     * unknown is unclassified and satisfies no requirement except itself.
     *
     * @var array<string, int>
     */
    private const RANKS = [
        'local' => 0,
        'self_hosted' => 1,
        'cloud' => 2,
        'unknown' => 3,
    ];

    /**
     * Get the human-readable label for the privacy level.
     */
    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local',
            self::SelfHosted => 'Self-hosted',
            self::Cloud => 'Cloud',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * Build the level from a stored column value, defaulting to unknown.
     *
     * Legacy or unclassified rows may carry null; the resolver must never
     * crash on them.
     *
     * @param  string|null  $value  The raw privacy_level column value.
     */
    public static function fromColumn(?string $value): self
    {
        return $value !== null ? (self::tryFrom($value) ?? self::Unknown) : self::Unknown;
    }

    /**
     * Whether this level is at least as private as the required level.
     *
     * Local satisfies every requirement; unknown satisfies only an
     * unknown requirement, because an unclassified provider may be
     * hosted anywhere.
     */
    public function isAtLeast(self $required): bool
    {
        return self::RANKS[$this->value] <= self::RANKS[$required->value];
    }
}
