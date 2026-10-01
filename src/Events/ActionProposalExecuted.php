<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Events;

use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a proposal's action completes successfully.
 *
 * Fired by AiActionProposal::markExecuted() when the proposal
 * transitions from executing to executed.
 */
final class ActionProposalExecuted
{
    use Dispatchable;

    /**
     * @param  AiActionProposal  $proposal  The executed proposal.
     * @param  array<string, mixed>  $result  The serialisable execution result.
     */
    public function __construct(
        public readonly AiActionProposal $proposal,
        public readonly array $result = [],
    ) {}
}
