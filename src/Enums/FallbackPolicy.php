<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Enums;

/**
 * Fallback policy attached to an AI provider.
 *
 * Controls which other providers may replace this one when it cannot serve
 * a request (runtime failover or resolution degradation). The chosen
 * provider's policy is authoritative: candidates that do not satisfy it are
 * excluded from fallback, even when they are enabled and reachable.
 */
enum FallbackPolicy: string
{
    /** Degrade only to local-network providers. */
    case LocalOnly = 'local_only';

    /** Degrade only to providers of the same privacy level. */
    case SamePrivacyLevel = 'same_privacy_level';

    /** Degrade to any provider, including public cloud. */
    case AllowCloud = 'allow_cloud';

    /**
     * Get the human-readable label for the policy.
     */
    public function label(): string
    {
        return match ($this) {
            self::LocalOnly => 'Local only',
            self::SamePrivacyLevel => 'Same privacy level',
            self::AllowCloud => 'Allow cloud',
        };
    }

    /**
     * Whether a candidate provider may replace one governed by this policy.
     *
     * @param  PrivacyLevel  $primary  The privacy level of the chosen provider.
     * @param  PrivacyLevel  $candidate  The privacy level of the fallback candidate.
     */
    public function accepts(PrivacyLevel $primary, PrivacyLevel $candidate): bool
    {
        return match ($this) {
            self::LocalOnly => $candidate === PrivacyLevel::Local,
            self::SamePrivacyLevel => $candidate === $primary,
            self::AllowCloud => true,
        };
    }

    /**
     * Build the policy from a stored column value, defaulting to
     * same_privacy_level (the most conservative general choice).
     *
     * @param  string|null  $value  The raw fallback_policy column value.
     */
    public static function fromColumn(?string $value): self
    {
        return $value !== null ? (self::tryFrom($value) ?? self::SamePrivacyLevel) : self::SamePrivacyLevel;
    }
}
