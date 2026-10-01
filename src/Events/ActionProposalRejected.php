<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Events;

use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a proposal is rejected by a decider.
 *
 * Fired only when $deciderId is provided (non-null) to avoid duplicating
 * events with the decision layer (ProposalDecisions).
 */
final class ActionProposalRejected
{
    use Dispatchable;

    /**
     * @param  AiActionProposal  $proposal  The rejected proposal.
     * @param  int|string|null  $deciderId  The id of the user who rejected.
     */
    public function __construct(
        public readonly AiActionProposal $proposal,
        public readonly int|string|null $deciderId,
    ) {}
}
