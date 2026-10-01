<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Events;

use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a new action proposal is created.
 *
 * Covers all creation paths: create(), createValidated(),
 * createValidatedWithSource().
 */
final class ActionProposalCreated
{
    use Dispatchable;

    /**
     * @param  AiActionProposal  $proposal  The newly created proposal.
     */
    public function __construct(
        public readonly AiActionProposal $proposal,
    ) {}
}
