<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Proposals;

use HomeSide\AiAgents\Models\AiActionProposal;
use HomeSide\AiAgents\Proposals\SdkApprovalBridge;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use Laravel\Ai\Approvals\PendingApproval;

/**
 * The SDK approvals ↔ Action Proposals bridge.
 */
final class SdkApprovalBridgeTest extends TestCase
{
    private function bridge(): SdkApprovalBridge
    {
        return new SdkApprovalBridge(proposalType: 'sdk_approval');
    }

    /**
     * A pending approval becomes a pending proposal carrying the tool-call
     * id, tool and arguments.
     */
    public function test_pending_approval_becomes_proposal(): void
    {
        $user = TestUser::create(['name' => 'Test', 'email' => 'bridge@example.com']);

        $proposals = $this->bridge()->proposalsFromPendingApprovals(
            [new PendingApproval('call_1', 'delete_file', ['path' => '/tmp/x'], 'Dangerous')],
            userId: $user->id,
        );

        $this->assertCount(1, $proposals);
        $this->assertSame(AiActionProposal::STATUS_PENDING, $proposals[0]->status);
        $this->assertSame('sdk_approval', $proposals[0]->type);
        $this->assertSame('call_1', $proposals[0]->payload['tool_call_id']);
        $this->assertSame('delete_file', $proposals[0]->payload['tool']);
        $this->assertSame('Dangerous', $proposals[0]->reason);
    }

    /**
     * An accepted proposal maps to an SDK approval decision.
     */
    public function test_accepted_proposal_maps_to_approve_decision(): void
    {
        $user = TestUser::create(['name' => 'Test', 'email' => 'approve@example.com']);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'sdk_approval',
            'payload' => ['tool_call_id' => 'call_9', 'tool' => 'read', 'arguments' => []],
            'status' => AiActionProposal::STATUS_ACCEPTED,
        ]);

        $decisions = $this->bridge()->decisionsFromProposals([$proposal]);

        $this->assertSame('approve', $decisions->get('call_9')?->action);
    }

    /**
     * A rejected proposal maps to a rejection so the tool never runs.
     */
    public function test_rejected_proposal_maps_to_reject_decision(): void
    {
        $user = TestUser::create(['name' => 'Test', 'email' => 'reject@example.com']);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'sdk_approval',
            'payload' => ['tool_call_id' => 'call_7', 'tool' => 'read', 'arguments' => []],
            'status' => AiActionProposal::STATUS_REJECTED,
        ]);

        $decisions = $this->bridge()->decisionsFromProposals([$proposal]);

        $this->assertSame('reject', $decisions->get('call_7')?->action);
    }

    /**
     * An accepted proposal with an amended payload maps to an edit decision.
     */
    public function test_amended_payload_maps_to_edit_decision(): void
    {
        $user = TestUser::create(['name' => 'Test', 'email' => 'edit@example.com']);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'sdk_approval',
            'payload' => ['tool_call_id' => 'call_5', 'tool' => 'write', 'arguments' => ['path' => '/safe']],
            'original_payload' => ['tool_call_id' => 'call_5', 'tool' => 'write', 'arguments' => ['path' => '/unsafe']],
            'status' => AiActionProposal::STATUS_ACCEPTED,
        ]);

        $decisions = $this->bridge()->decisionsFromProposals([$proposal]);
        $decision = $decisions->get('call_5');

        $this->assertSame('edit', $decision?->action);
        $this->assertSame(['path' => '/safe'], $decision?->arguments);
    }
}
