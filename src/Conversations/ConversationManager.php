<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Conversations;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Enums\TenantIsolation;
use HomeSide\AiAgents\Exceptions\ConversationNotFoundException;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiConversation;
use Illuminate\Support\Str;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;

/**
 * Owns the lifecycle of the canonical conversation record.
 *
 * The SDK's RememberConversation middleware calls
 * ConversationStore::storeConversation() WITHOUT the agent, yet
 * AiConversation requires a valid agent. Letting the standard middleware
 * create the canonical row freely would therefore be unsafe. The manager
 * resolves or creates the conversation BEFORE prompt(), so the middleware
 * sees an existing currentConversation() and simply uses it.
 *
 * Responsibilities:
 * - Decide whether a given SDK agent is conversational (RemembersConversations).
 * - Resolve an existing, AUTHORIZED conversation (through the guard) or
 *   create a new one with the correct agent, owner and tenant.
 * - Discard a conversation that an execution created and left empty, so a
 *   failed first turn never leaves a ghost chat behind.
 */
final class ConversationManager
{
    public function __construct(
        private readonly ConversationAccessGuard $guard,
        private readonly ResolvesTenant $tenantResolver,
        private readonly AgentRegistry $registry,
    ) {}

    /**
     * Whether conversation memory is enabled for this installation.
     */
    public function enabled(): bool
    {
        return (bool) config('ai-agents.conversations.enabled', false);
    }

    /**
     * Whether the given SDK agent remembers conversations.
     *
     * Mirrors Laravel AI's own criterion (RememberConversation::appliesTo):
     * implementing the contract or using the SDK trait.
     */
    public function remembers(Agent $sdkAgent): bool
    {
        return $sdkAgent instanceof RemembersConversationsContract
            || in_array(RemembersConversationsTrait::class, class_uses_recursive($sdkAgent), true);
    }

    /**
     * Resolve an existing authorised conversation, or create a new one.
     *
     * @param  string  $agentKey  The canonical agent key.
     * @param  AiExecutionContextData  $context  Execution context (owner + tenant).
     * @param  string|null  $conversationId  An existing id to continue, or null to create.
     *
     * @throws ConversationNotFoundException When the
     *                                       id does not resolve to an accessible conversation.
     */
    public function resolveOrCreate(
        string $agentKey,
        AiExecutionContextData $context,
        ?string $conversationId,
        ?string $userMessage = null,
    ): AiConversation {
        if ($conversationId !== null && $conversationId !== '') {
            $conversation = $this->guard->authorize($conversationId, $agentKey, $context);

            // Mark activity AFTER authorising (never before, so a foreign id
            // cannot trigger writes) so a conversation being continued stops
            // being a pruning candidate for the current retention window.
            $conversation->touch();

            // If pruning deleted the row between authorise() and touch(), the
            // refresh fails BEFORE the model call, so a run never proceeds on
            // a conversation that no longer exists.
            $conversation->refresh();

            return $conversation;
        }

        return $this->createFor($agentKey, $context, $userMessage);
    }

    /**
     * Create a new canonical conversation for the agent and owner.
     *
     * The title is never derived from the first prompt in plaintext: the
     * default 'neutral' strategy stores a fixed label, and the optional
     * 'prompt' strategy stores the (encrypted) user message once. The title
     * column is encrypted by AiConversation's cast.
     */
    public function createFor(
        string $agentKey,
        AiExecutionContextData $context,
        ?string $userMessage = null,
    ): AiConversation {
        $attributes = [
            'user_id' => $context->userId,
            'agent' => $agentKey,
            'title' => $this->titleFor($userMessage),
        ];

        if ($this->tenantResolver->isolation() === TenantIsolation::Column && $context->tenantId !== null) {
            $attributes[$this->tenantResolver->foreignKey()] = $context->tenantId;
        }

        /** @var AiConversation $conversation */
        $conversation = AiConversation::create($attributes);

        return $conversation;
    }

    /**
     * Whether the conversation already holds at least one message.
     */
    public function hasMessages(AiConversation $conversation): bool
    {
        return $conversation->messages()->exists();
    }

    /**
     * Delete a conversation that an execution created and left empty.
     *
     * Prevents ghost chats when a first turn fails before any message is
     * stored. Returns true when the conversation was removed.
     */
    public function discardIfEmpty(AiConversation $conversation): bool
    {
        if ($this->hasMessages($conversation)) {
            return false;
        }

        return (bool) $conversation->delete();
    }

    /**
     * Resolve the canonical agent key for an SDK agent class.
     *
     * The SDK protocol passes a class-string; the package keys off the
     * canonical key. Returns the class itself as a last resort so callers
     * never end up with an empty identifier.
     *
     * @param  class-string|string  $agentClass
     */
    public function agentKeyForClass(string $agentClass): string
    {
        return $this->registry->keyForClass($agentClass) ?? $agentClass;
    }

    /**
     * Resolve the title to store for a new conversation.
     */
    private function titleFor(?string $userMessage): string
    {
        $strategy = (string) config('ai-agents.conversations.titles.strategy', 'neutral');

        if ($strategy === 'prompt' && is_string($userMessage) && $userMessage !== '') {
            return Str::limit($userMessage, 100, preserveWords: true);
        }

        return 'New conversation';
    }
}
