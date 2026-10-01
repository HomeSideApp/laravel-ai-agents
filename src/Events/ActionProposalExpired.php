<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Events;

use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a proposal expires due to stale expiry time.
 *
 * Fired by AiActionProposal::expireStale() for each proposal that
 * transitions from pending to expired.
 */
final class ActionProposalExpired
{
    use Dispatchable;

    /**
     * @param  AiActionProposal  $proposal  The expired proposal.
     */
    public function __construct(
        public readonly AiActionProposal $proposal,
    ) {}
}
