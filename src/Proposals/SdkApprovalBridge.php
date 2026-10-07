<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Proposals;

use HomeSide\AiAgents\Models\AiActionProposal;
use Illuminate\Support\Carbon;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;

/**
 * Bridges the SDK's native tool approvals with the package's Action Proposals.
 *
 * The two mechanisms serve the same goal — a human deciding whether an agent
 * side effect runs — but live at different layers:
 *
 * - SDK approvals ({@see PendingApproval}, {@see Decision}) pause a run
 *   mid-loop and resume it with the human's decision, keyed by tool-call id.
 *   They are ephemeral: they exist for the duration of one paused invocation.
 * - Action Proposals ({@see AiActionProposal}) are durable, queryable,
 *   authorizable and auditable rows with a full lifecycle, but do not pause
 *   the SDK loop themselves.
 *
 * This bridge lets a host that wants the durable Proposals UX drive an SDK
 * pause (and vice versa) without giving up either: the SDK stays the
 * approval transport; the package stays the source of truth for permissions,
 * auditing and expiry.
 *
 * Precedence contract:
 * - Authorisation ALWAYS goes through AuthorizesProposalDecisions via
 *   ProposalDecisions; a decision never bypasses the package's authorizer.
 * - A proposal only resumes an SDK run when it is accepted; rejected and
 *   expired proposals map to a rejection decision so the tool never runs.
 */
final class SdkApprovalBridge
{
    /**
     * @param  string  $proposalType  The AiActionProposal type used for
     *                                approvals surfaced from an SDK pause.
     */
    public function __construct(
        private readonly string $proposalType = 'sdk_approval',
    ) {}

    /**
     * Persist one pending proposal per SDK pending approval.
     *
     * Each approval becomes an `AiActionProposal` whose payload records the
     * tool, its arguments and the SDK tool-call id, so the decision can be
     * routed back to the exact paused call.
     *
     * @param  iterable<int, PendingApproval>  $approvals  The SDK's pending approvals.
     * @param  int|string  $userId  The owner who must decide.
     * @param  string|null  $conversationId  The conversation the run belongs to, if any.
     * @param  string|null  $aiRunId  The AiRun that produced the pause, if known.
     * @param  Carbon|null  $expiresAt  Optional TTL; null lets the package default apply.
     * @return list<AiActionProposal> The created proposals, in input order.
     */
    public function proposalsFromPendingApprovals(
        iterable $approvals,
        int|string $userId,
        ?string $conversationId = null,
        ?string $aiRunId = null,
        ?Carbon $expiresAt = null,
    ): array {
        $proposals = [];

        foreach ($approvals as $approval) {
            $proposals[] = AiActionProposal::createValidated([
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'type' => $this->proposalType,
                'payload' => [
                    'tool_call_id' => $approval->id,
                    'tool' => $approval->tool,
                    'arguments' => $approval->arguments,
                ],
                'reason' => $approval->reason,
                'status' => AiActionProposal::STATUS_PENDING,
                'expires_at' => $expiresAt,
                'ai_run_id' => $aiRunId,
            ]);
        }

        return $proposals;
    }

    /**
     * Translate the decided proposals back into SDK approval decisions.
     *
     * Accepted proposals approve (or edit, when the accepted payload carries
     * amended `arguments`); rejected and expired proposals reject, so a
     * proposal the host never accepted can never execute the tool.
     *
     * @param  iterable<int, AiActionProposal>  $proposals  The decided proposals.
     * @return Decisions The ID-keyed SDK decisions, ready to resume a run.
     */
    public function decisionsFromProposals(iterable $proposals): Decisions
    {
        $decisions = [];

        foreach ($proposals as $proposal) {
            $toolCallId = (string) ($proposal->payload['tool_call_id'] ?? '');

            if ($toolCallId === '') {
                continue;
            }

            $decisions[$toolCallId] = $this->decisionFor($proposal);
        }

        return Decisions::from($decisions);
    }

    /**
     * Map a single proposal's status to an SDK approval decision.
     */
    private function decisionFor(AiActionProposal $proposal): Decision
    {
        if ($proposal->status === AiActionProposal::STATUS_ACCEPTED) {
            $arguments = $proposal->payload['arguments'] ?? null;

            // An amended payload (payload differing from original_payload)
            // runs the tool with the human's edits instead of the model's.
            if (is_array($arguments) && $this->wasAmended($proposal)) {
                return Decision::edit($arguments);
            }

            return Decision::approve();
        }

        return Decision::reject($proposal->decision_note);
    }

    /**
     * Whether the proposal's payload was amended before acceptance.
     */
    private function wasAmended(AiActionProposal $proposal): bool
    {
        return $proposal->original_payload !== null
            && $proposal->original_payload !== $proposal->payload;
    }
}
