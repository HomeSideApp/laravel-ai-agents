<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Exceptions;

use RuntimeException;

/**
 * Raised when a conversation id is supplied for an agent that is not
 * conversational.
 *
 * Only agents implementing the SDK's RemembersConversations contract may
 * carry memory. Passing a conversation id to a stateless agent (a
 * generator, extractor, image generator, ...) is a caller bug and fails
 * loudly instead of being silently ignored, which would hide the mistake
 * and could mask a security problem.
 */
final class ConversationNotSupportedException extends RuntimeException
{
    /**
     * Build the exception for a stateless agent that received an id.
     */
    public static function forAgent(string $agentKey): self
    {
        return new self(
            "The AI agent [{$agentKey}] does not remember conversations, "
            .'so it cannot continue a conversation.',
        );
    }
}
