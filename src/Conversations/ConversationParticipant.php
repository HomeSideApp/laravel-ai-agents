<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Conversations;

/**
 * Lightweight conversation participant used when the host user model cannot
 * be resolved.
 *
 * The SDK derives the participant_type/participant_id pair from the object
 * passed to continue(as: ...). Exposing the same stable `id` keeps that
 * pair consistent across runs even without an Eloquent instance.
 */
final class ConversationParticipant
{
    public function __construct(
        public readonly int|string $id,
    ) {}
}
