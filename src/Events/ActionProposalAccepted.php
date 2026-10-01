<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Events;

use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a proposal is accepted by a decider.
 *
 * Fired only when $deciderId is provided (non-null) to avoid duplicating
 * events with the decision layer (ProposalDecisions).
 */
final class ActionProposalAccepted
{
    use Dispatchable;

    /**
     * @param  AiActionProposal  $proposal  The accepted proposal.
     * @param  int|string|null  $deciderId  The id of the user who accepted.
     */
    public function __construct(
        public readonly AiActionProposal $proposal,
        public readonly int|string|null $deciderId,
    ) {}
}
