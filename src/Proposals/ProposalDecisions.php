<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Proposals;

use HomeSide\AiAgents\Contracts\AuthorizesProposalDecisions;
use HomeSide\AiAgents\Jobs\ExecuteActionProposalJob;
use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Singleton service for authorizing and applying proposal decisions.
 *
 * Delegates the atomic transition to the model (accept/reject) so that
 * model events are fired exactly once.  This service MUST NOT fire its
 * own events — the model handles that.
 */
final class ProposalDecisions
{
    /** Decision to accept the proposal. */
    public const DECISION_ACCEPT = 'accept';

    /** Decision to reject the proposal. */
    public const DECISION_REJECT = 'reject';

    /**
     * @param  AuthorizesProposalDecisions  $authorizer  The authorizer.
     * @param  ProposalHandlerRegistry  $handlers  The handler registry.
     */
    public function __construct(
        private readonly AuthorizesProposalDecisions $authorizer,
        private readonly ProposalHandlerRegistry $handlers,
    ) {}

    /**
     * Apply a decision on a proposal.
     *
     * @param  AiActionProposal  $proposal  The proposal to decide on.
     * @param  int|string  $userId  The user making the decision.
     * @param  string  $decision  Either 'accept' or 'reject'.
     * @param  array<string, mixed>|null  $payload  Amended payload (accept only, validated against handler rules).
     * @param  string|null  $note  Decision note.
     *
     * @throws \InvalidArgumentException If $decision is not 'accept' or 'reject'.
     * @throws AuthorizationException If the user is not authorized.
     * @throws ValidationException If the amended payload fails validation.
     */
    public function decide(
        AiActionProposal $proposal,
        int|string $userId,
        string $decision,
        ?array $payload = null,
        ?string $note = null,
    ): bool {
        if (! in_array($decision, [self::DECISION_ACCEPT, self::DECISION_REJECT], true)) {
            throw new \InvalidArgumentException(
                "Unexpected decision value [{$decision}]. Must be 'accept' or 'reject'."
            );
        }

        // Authorization check.
        if (! $this->authorizer->canDecide($proposal, $userId)) {
            throw new AuthorizationException('You are not authorized to decide on this proposal.');
        }

        // Handle accept path: resolve handler, validate payload if provided.
        if ($decision === self::DECISION_ACCEPT) {
            $handler = $this->handlers->get($proposal->type);

            if ($handler !== null && $payload !== null) {
                $proposal->validatePayloadForWrite($payload, $handler->rules());
            }
        }

        // Apply the atomic transition via the model (model fires the event).
        $success = match ($decision) {
            self::DECISION_ACCEPT => $proposal->accept($userId, $payload, $note),
            self::DECISION_REJECT => $proposal->reject($userId, $note),
        };

        if (! $success) {
            return false;
        }

        // Dispatch execution job if accept + handler exists.
        if ($decision === self::DECISION_ACCEPT && $this->handlers->has($proposal->type)) {
            $executeMode = (string) config('ai-agents.proposals.execute', 'queue');

            // Resolve tenant key: when isolation === database, read the
            // callable or string-resolvable from tenant.current_key. This
            // is encapsulated in a single place so the rest of the codebase
            // stays decoupled from the tenant resolution strategy.
            $tenantKey = null;
            $isolation = (string) config('ai-agents.tenant.isolation', 'none');

            if ($isolation === 'database') {
                $resolver = config('ai-agents.tenant.current_key');
                if (is_callable($resolver)) {
                    $tenantKey = $resolver();
                } elseif (is_string($resolver) && $resolver !== '') {
                    $tenantKey = $resolver;
                }
            }

            match ($executeMode) {
                'queue' => ExecuteActionProposalJob::dispatch($proposal->id, $tenantKey),
                'sync' => ExecuteActionProposalJob::dispatchSync($proposal->id, $tenantKey),
                'none' => null,
                default => throw new \InvalidArgumentException(
                    "Unknown execute mode [{$executeMode}]. Must be 'queue', 'sync', or 'none'."
                ),
            };
        }

        return true;
    }

    /**
     * Apply the authorizer's decision scope to a query.
     *
     * @param  Builder< AiActionProposal >  $query  The query to scope.
     * @param  int|string  $userId  The user id.
     * @return Builder< AiActionProposal >
     */
    public function applyDecisionScope(Builder $query, int|string $userId): Builder
    {
        return $this->authorizer->applyDecisionScope($query, $userId);
    }

    /**
     * Singleton instance (resolved from container).
     */
    public static function instance(): self
    {
        return app(self::class);
    }
}
