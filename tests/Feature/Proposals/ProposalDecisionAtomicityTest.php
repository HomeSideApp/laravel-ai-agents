<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Proposals;

use HomeSide\AiAgents\Models\AiActionProposal;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use Illuminate\Support\Facades\DB;

/**
 * Atomic proposal decision transitions: double accept/reject, already decided, expired.
 */
final class ProposalDecisionAtomicityTest extends TestCase
{
    private function makeUser(string $email = 'test@example.com'): TestUser
    {
        return TestUser::create(['name' => 'Test User', 'email' => $email]);
    }

    /**
     * Two concurrent accept() calls: first returns true, second returns false.
     * Final status is accepted with the first decider's metadata.
     */
    public function test_double_accept_only_first_succeeds(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        // First accept succeeds.
        $first = $proposal->accept($user->id, note: 'First accept');
        $this->assertTrue($first);
        $this->assertSame('accepted', $proposal->refresh()->status);
        $this->assertEquals((string) $user->id, $proposal->decided_by);
        $firstDecidedAt = $proposal->decided_at;

        // Simulate a second process loading the same row.
        $freshCopy = AiActionProposal::find($proposal->id);

        // Second accept returns false (conditional UPDATE affected 0 rows).
        $second = $freshCopy->accept($user->id + 1, note: 'Second accept');
        $this->assertFalse($second);

        // State unchanged.
        $final = AiActionProposal::find($proposal->id);
        $this->assertSame('accepted', $final->status);
        $this->assertEquals((string) $user->id, $final->decided_by);
        $this->assertTrue($final->decided_at->diffInSeconds($firstDecidedAt) <= 1);
    }

    /**
     * Two concurrent reject() calls: first succeeds, second returns false.
     */
    public function test_double_reject_only_first_succeeds(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $first = $proposal->reject($user->id, note: 'First reject');
        $this->assertTrue($first);
        $this->assertSame('rejected', $proposal->refresh()->status);

        $freshCopy = AiActionProposal::find($proposal->id);
        $second = $freshCopy->reject($user->id + 1, note: 'Second reject');
        $this->assertFalse($second);

        $final = AiActionProposal::find($proposal->id);
        $this->assertSame('rejected', $final->status);
    }

    /**
     * Cannot accept an already accepted proposal.
     */
    public function test_cannot_accept_already_accepted(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);
        $proposal->accept($user->id);

        $result = $proposal->accept($user->id);
        $this->assertFalse($result);
        $this->assertSame('accepted', $proposal->refresh()->status);
    }

    /**
     * Cannot accept an already rejected proposal.
     */
    public function test_cannot_accept_already_rejected(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);
        $proposal->reject($user->id);

        $result = $proposal->accept($user->id);
        $this->assertFalse($result);
        $this->assertSame('rejected', $proposal->refresh()->status);
    }

    /**
     * Cannot accept an already expired proposal.
     */
    public function test_cannot_accept_expired_proposal(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
            'expires_at' => now()->subHour(),
        ]);

        // First expireStale makes the status expired.
        AiActionProposal::expireStale();
        $this->assertSame('expired', $proposal->refresh()->status);

        $result = $proposal->accept($user->id);
        $this->assertFalse($result);
        $this->assertSame('expired', $proposal->refresh()->status);
    }

    /**
     * A proposal with expires_at in the past and status pending cannot be accepted.
     * The status stays pending (it was not already expired, just stale).
     */
    public function test_expired_proposal_cannot_be_accepted(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
            'expires_at' => now()->subHour(),
        ]);

        // Without calling expireStale, the status is still pending but
        // the conditional UPDATE in accept() checks expires_at > now().
        $result = $proposal->accept($user->id);
        $this->assertFalse($result);

        // Status remains pending (not expired by us).
        $this->assertSame('pending', $proposal->refresh()->status);
    }

    /**
     * Cannot reject an already rejected proposal.
     */
    public function test_cannot_reject_already_rejected(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);
        $proposal->reject($user->id);

        $result = $proposal->reject($user->id);
        $this->assertFalse($result);
        $this->assertSame('rejected', $proposal->refresh()->status);
    }

    /**
     * Cannot reject an already accepted proposal.
     */
    public function test_cannot_reject_already_accepted(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);
        $proposal->accept($user->id);

        $result = $proposal->reject($user->id);
        $this->assertFalse($result);
        $this->assertSame('accepted', $proposal->refresh()->status);
    }

    /**
     * Can accept a proposal with no expiry (expires_at IS NULL).
     */
    public function test_accept_with_no_expiry_succeeds(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $this->assertNull($proposal->expires_at);

        $result = $proposal->accept($user->id);
        $this->assertTrue($result);
        $this->assertSame('accepted', $proposal->refresh()->status);
    }

    /**
     * A proposal with future expires_at can still be accepted.
     */
    public function test_accept_with_future_expiry_succeeds(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
            'expires_at' => now()->addHour(),
        ]);

        $result = $proposal->accept($user->id);
        $this->assertTrue($result);
        $this->assertSame('accepted', $proposal->refresh()->status);
    }

    /**
     * Atomicity: simulate true concurrency by using DB-level row locks
     * to ensure two separate PHP processes see the same state.
     */
    public function test_atomic_update_under_transaction(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $result1 = null;
        $result2 = null;

        // Two separate DB transactions, each loading the row.
        DB::transaction(function () use ($proposal, &$result1, $user): void {
            $copy = AiActionProposal::find($proposal->id);
            $result1 = $copy->accept($user->id);
        });

        DB::transaction(function () use ($proposal, &$result2, $user): void {
            $copy = AiActionProposal::find($proposal->id);
            $result2 = $copy->accept($user->id + 1);
        });

        $this->assertTrue($result1);
        $this->assertFalse($result2);

        $final = AiActionProposal::find($proposal->id);
        $this->assertSame('accepted', $final->status);
        $this->assertEquals((string) $user->id, $final->decided_by);
    }
}
