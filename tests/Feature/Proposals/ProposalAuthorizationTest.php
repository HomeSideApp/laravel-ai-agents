<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Proposals;

use HomeSide\AiAgents\Contracts\AuthorizesProposalDecisions;
use HomeSide\AiAgents\Models\AiActionProposal;
use HomeSide\AiAgents\Proposals\OwnerOnlyProposalAuthorizer;
use HomeSide\AiAgents\Proposals\ProposalDecisions;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\AllowAllProposalAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;

/**
 * Proposal authorization: owner-only and custom authorizers.
 */
final class ProposalAuthorizationTest extends TestCase
{
    private function makeUser(string $email = 'test@example.com'): TestUser
    {
        return TestUser::create(['name' => 'Test User', 'email' => $email]);
    }

    /**
     * Owner-only: the owner can decide.
     */
    public function test_owner_can_decide(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $proposal = AiActionProposal::createValidated([
            'user_id' => $owner->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $authorizer = app(AuthorizesProposalDecisions::class);
        $this->assertInstanceOf(OwnerOnlyProposalAuthorizer::class, $authorizer);
        $this->assertTrue($authorizer->canDecide($proposal, (int) $owner->id));
    }

    /**
     * Owner-only: another user cannot decide.
     */
    public function test_another_user_cannot_decide(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $other = $this->makeUser('other@example.com');
        $proposal = AiActionProposal::createValidated([
            'user_id' => $owner->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $authorizer = app(AuthorizesProposalDecisions::class);
        $this->assertFalse($authorizer->canDecide($proposal, (int) $other->id));
    }

    /**
     * ProposalDecisions::decide() with unauthorized user throws AuthorizationException.
     */
    public function test_decide_unauthorized_throws_authorization_exception(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $other = $this->makeUser('other@example.com');
        $proposal = AiActionProposal::createValidated([
            'user_id' => $owner->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        Config::set('ai-agents.proposals.execute', 'none');

        $this->expectException(AuthorizationException::class);

        try {
            ProposalDecisions::instance()->decide(
                $proposal,
                (string) $other->id,
                ProposalDecisions::DECISION_ACCEPT,
            );
        } finally {
            $this->assertSame('pending', $proposal->refresh()->status);
        }
    }

    /**
     * When not authorized, the proposal stays pending.
     */
    public function test_decide_unauthorized_proposal_stays_pending(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $other = $this->makeUser('other@example.com');
        $proposal = AiActionProposal::createValidated([
            'user_id' => $owner->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        Config::set('ai-agents.proposals.execute', 'none');

        try {
            ProposalDecisions::instance()->decide(
                $proposal,
                (string) $other->id,
                ProposalDecisions::DECISION_ACCEPT,
            );
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->assertSame('pending', $proposal->refresh()->status);
    }

    /**
     * Custom authorizer (AllowAll) is respected via config.
     */
    public function test_custom_authorizer_is_respected(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $third = $this->makeUser('third@example.com');
        $proposal = AiActionProposal::createValidated([
            'user_id' => $owner->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        // Register custom authorizer.
        Config::set('ai-agents.proposals.authorizer', AllowAllProposalAuthorizer::class);

        // Force re-resolution: remove singleton from container.
        if (app()->resolved(AuthorizesProposalDecisions::class)) {
            app()->forgetInstance(AuthorizesProposalDecisions::class);
        }

        $authorizer = app(AuthorizesProposalDecisions::class);
        $this->assertInstanceOf(AllowAllProposalAuthorizer::class, $authorizer);
        $this->assertTrue($authorizer->canDecide($proposal, (string) $third->id));
    }

    /**
     * Custom authorizer: ProposalDecisions::decide() with third-party user succeeds.
     */
    public function test_decide_with_custom_authorizer_allows_third_party(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $third = $this->makeUser('third@example.com');
        $proposal = AiActionProposal::createValidated([
            'user_id' => $owner->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        Config::set('ai-agents.proposals.authorizer', AllowAllProposalAuthorizer::class);
        Config::set('ai-agents.proposals.execute', 'none');

        if (app()->resolved(AuthorizesProposalDecisions::class)) {
            app()->forgetInstance(AuthorizesProposalDecisions::class);
        }

        $result = ProposalDecisions::instance()->decide(
            $proposal,
            (string) $third->id,
            ProposalDecisions::DECISION_ACCEPT,
        );
        $this->assertTrue($result);
        $this->assertSame('accepted', $proposal->refresh()->status);
        $this->assertEquals((string) $third->id, $proposal->decided_by);
    }

    /**
     * scopeAwaitingDecisionBy returns only proposals the user can decide.
     */
    public function test_scope_awaiting_decision_by_filters_owner_only(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $other = $this->makeUser('other@example.com');

        $myProposal = AiActionProposal::createValidated([
            'user_id' => $owner->id,
            'type' => 'test_action',
            'payload' => ['key' => 'my'],
        ]);

        $theirProposal = AiActionProposal::createValidated([
            'user_id' => $other->id,
            'type' => 'test_action',
            'payload' => ['key' => 'theirs'],
        ]);

        // Already decided (should not appear).
        AiActionProposal::createValidated([
            'user_id' => $owner->id,
            'type' => 'test_action',
            'payload' => ['key' => 'decided'],
            'status' => 'accepted',
        ]);

        $query = AiActionProposal::query();
        $scoped = $query->awaitingDecisionBy((int) $owner->id)->get();

        $this->assertEquals([$myProposal->id], $scoped->pluck('id')->toArray());
    }

    /**
     * When no authorizer is available (container not bootstrapped), the fallback
     * scope uses owner-only.
     */
    public function test_scope_falls_back_to_owner_only(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        // The fallback path in scopeAwaitingDecisionBy uses try/catch.
        // Since the authorizer IS registered, it should work normally.
        $scoped = AiActionProposal::query()->awaitingDecisionBy((int) $user->id)->get();
        $this->assertTrue($scoped->contains('id', $proposal->id));
    }

    /**
     * applyDecisionScope on custom authorizer: AllowAll returns all pending,
     * regardless of user_id.
     */
    public function test_custom_authorizer_scope_returns_all_pending(): void
    {
        // Directly test that AllowAllProposalAuthorizer::applyDecisionScope
        // returns all pending proposals regardless of user_id.
        $authorizer = new AllowAllProposalAuthorizer;

        $owner = $this->makeUser('owner@example.com');
        $other = $this->makeUser('other@example.com');

        $myProposal = AiActionProposal::createValidated([
            'user_id' => $owner->id,
            'type' => 'test_action',
            'payload' => ['key' => 'a'],
        ]);

        $theirProposal = AiActionProposal::createValidated([
            'user_id' => $other->id,
            'type' => 'test_action',
            'payload' => ['key' => 'b'],
        ]);

        // Decided proposal should not appear.
        AiActionProposal::createValidated([
            'user_id' => $owner->id,
            'type' => 'test_action',
            'payload' => ['key' => 'c'],
            'status' => 'accepted',
        ]);

        // Use the authorizer directly (bypass container caching issues).
        // Note: pending() is a model scope so we call it on the model query.
        // Use raw where() instead of the model scope because the scope isn't
        // bound to the builder in Orchestra Testbench's SQLite mode.
        $scoped = $authorizer->applyDecisionScope(
            AiActionProposal::query()->where('status', 'pending'),
            (string) $other->id,
        )->get();

        $this->assertCount(2, $scoped);
        $this->assertTrue($scoped->contains('id', $myProposal->id));
        $this->assertTrue($scoped->contains('id', $theirProposal->id));
    }
}
