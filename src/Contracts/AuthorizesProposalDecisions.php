<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contract for authorizing proposal decisions.
 *
 * Determines whether a user can decide on a given proposal and provides
 * a query scope to filter the proposals the user can decide on.
 */
interface AuthorizesProposalDecisions
{
    /**
     * Check whether the given user can decide on the proposal.
     *
     * @param  AiActionProposal  $proposal  The proposal to check.
     * @param  int|string  $userId  The user id.
     */
    public function canDecide(AiActionProposal $proposal, int|string $userId): bool;

    /**
     * Apply a query scope to filter the proposals that a user can decide on.
     *
     * By default this filters to the user's own proposals (owner-only).
     * Custom authorizers may extend this logic.
     *
     * @param  Builder<AiActionProposal>  $query  The query to scope.
     * @param  int|string  $userId  The user id.
     * @return Builder<AiActionProposal>
     */
    public function applyDecisionScope(Builder $query, int|string $userId): Builder;
}
