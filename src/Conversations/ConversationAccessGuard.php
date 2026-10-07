<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Conversations;

use HomeSide\AiAgents\Contracts\ResolvesTenant;
use HomeSide\AiAgents\Enums\TenantIsolation;
use HomeSide\AiAgents\Exceptions\ConversationNotFoundException;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiConversation;

/**
 * Resolves a conversation id to an AUTHORIZED conversation in one query.
 *
 * This is the primary IDOR defence inside the package (the host's Policy is
 * defence in depth, not the mechanism). A conversation is only returned
 * when owner, agent and tenant all match the execution context at once:
 *
 *     AiConversation::query()
 *         ->whereKey($conversationId)
 *         ->forUser($context->userId)
 *         ->forAgent($agentKey)
 *         ->forTenant($authorizedTenant)
 *         ->first();
 *
 * There is intentionally NO `find($id)` followed by permission checks:
 * splitting the lookup from the authorization check invites future
 * mistakes and creates observable timing/behaviour differences between
 * "not found" and "not yours".
 *
 * Isolation behaviour:
 * - column: owner + agent + tenant scopes, using the tenant authorised by
 *   the bound {@see ResolvesTenant} (never a raw request value).
 * - database: the active tenant connection already isolates physically, so
 *   only owner + agent are checked here.
 * - none: owner + agent.
 *
 * A missing conversation and an inaccessible conversation produce the SAME
 * {@see ConversationNotFoundException}, so UUIDs do not become an
 * enumeration oracle.
 */
final class ConversationAccessGuard
{
    public function __construct(
        private readonly ResolvesTenant $tenantResolver,
    ) {}

    /**
     * Resolve the conversation, throwing when it is not accessible.
     *
     * @param  string  $conversationId  The conversation UUID to resolve.
     * @param  string  $agentKey  The canonical agent key that must own it.
     * @param  AiExecutionContextData  $context  The execution context
     *                                           (owner + tenant).
     *
     * @throws ConversationNotFoundException When the conversation does not
     *                                       exist or is not accessible.
     */
    public function authorize(
        string $conversationId,
        string $agentKey,
        AiExecutionContextData $context,
    ): AiConversation {
        return $this->authorizeOwnedBy(
            conversationId: $conversationId,
            agentKey: $agentKey,
            userId: $context->userId,
            tenantId: $context->tenantId,
        );
    }

    /**
     * Resolve the conversation, throwing when it is not accessible to the
     * given owner and tenant.
     *
     * Single-query core used both by the execution flow (through the
     * context overload) and by the conversation store, whose SDK-facing
     * methods carry a participant id rather than an execution context.
     * When $userId is null the owner scope is skipped, but the agent scope
     * (and the tenant scope in column mode) always apply.
     *
     * @param  string  $conversationId  The conversation UUID to resolve.
     * @param  string  $agentKey  The canonical agent key that must own it.
     * @param  int|string|null  $userId  The owner id, or null to skip the owner scope.
     * @param  int|string|null  $tenantId  The explicit tenant id, if any.
     *
     * @throws ConversationNotFoundException When the conversation does not
     *                                       exist or is not accessible.
     */
    public function authorizeOwnedBy(
        string $conversationId,
        string $agentKey,
        int|string|null $userId,
        int|string|null $tenantId = null,
    ): AiConversation {
        $query = AiConversation::query()
            ->whereKey($conversationId)
            ->forAgent($agentKey);

        if ($userId !== null) {
            $query->forUser($userId);
        }

        if ($this->tenantResolver->isolation() === TenantIsolation::Column) {
            // Scope to the tenant the ResolvesTenant contract authorised for
            // this user; a tenant the user does not belong to resolves to
            // null and therefore never matches a scoped conversation.
            $authorizedTenant = $this->tenantResolver->resolveAccessible($userId, $tenantId);

            $query->forTenant($authorizedTenant);
        }

        /** @var AiConversation|null $conversation */
        $conversation = $query->first();

        if ($conversation === null) {
            throw ConversationNotFoundException::forId($conversationId);
        }

        return $conversation;
    }
}
