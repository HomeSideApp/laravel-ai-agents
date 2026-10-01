<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Events;

use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a proposal's action fails.
 *
 * Fired by AiActionProposal::markFailed() when the proposal
 * transitions from executing (or accepted) to failed.
 */
final class ActionProposalFailed
{
    use Dispatchable;

    /**
     * @param  AiActionProposal  $proposal  The failed proposal.
     * @param  string  $error  The error message.
     */
    public function __construct(
        public readonly AiActionProposal $proposal,
        public readonly string $error = '',
    ) {}
}
