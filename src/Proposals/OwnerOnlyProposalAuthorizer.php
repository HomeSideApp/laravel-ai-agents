<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Proposals;

use HomeSide\AiAgents\Contracts\AuthorizesProposalDecisions;
use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Owner-only proposal authorizer.
 *
 * Only the user who received the proposal (owner) can decide on it.
 */
final class OwnerOnlyProposalAuthorizer implements AuthorizesProposalDecisions
{
    /**
     * @param  AiActionProposal  $proposal  The proposal to check.
     * @param  int|string  $userId  The user id.
     */
    public function canDecide(AiActionProposal $proposal, int|string $userId): bool
    {
        return (string) $proposal->user_id === (string) $userId;
    }

    /**
     * @param  Builder<AiActionProposal>  $query  The query to scope.
     * @param  int|string  $userId  The user id.
     * @return Builder<AiActionProposal>
     */
    public function applyDecisionScope(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
