<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Exceptions;

use RuntimeException;

/**
 * Raised when a conversation id cannot be resolved for the requesting
 * user, tenant and agent.
 *
 * A conversation that does not exist and a conversation the caller is not
 * allowed to access deliberately produce the SAME exception with the SAME
 * message, so conversation UUIDs never become an enumeration oracle: an
 * attacker cannot distinguish "not found" from "not yours".
 */
final class ConversationNotFoundException extends RuntimeException
{
    /**
     * Build the opaque, enumeration-safe exception for a conversation id.
     */
    public static function forId(int|string $conversationId): self
    {
        return new self('The conversation ['.(string) $conversationId.'] could not be found.');
    }
}
