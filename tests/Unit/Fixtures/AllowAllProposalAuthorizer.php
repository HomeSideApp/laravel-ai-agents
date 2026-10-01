<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Fixtures;

use HomeSide\AiAgents\Contracts\AuthorizesProposalDecisions;
use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Authorizer that allows every user to decide on every proposal.
 *
 * Used to test custom authorizer injection and scope resolution.
 */
final class AllowAllProposalAuthorizer implements AuthorizesProposalDecisions
{
    public function canDecide(AiActionProposal $proposal, int|string $userId): bool
    {
        return true;
    }

    public function applyDecisionScope(Builder $query, int|string $userId): Builder
    {
        // No filtering: every pending proposal is visible to every user.
        return $query->where('status', 'pending');
    }
}
