<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Proposals;

use HomeSide\AiAgents\Models\AiActionProposal;
use HomeSide\AiAgents\Proposals\ProposalDecisions;
use HomeSide\AiAgents\Proposals\ProposalHandlerRegistry;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\FakeProposalHandler;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

/**
 * Amended payload handling in proposal decisions.
 */
final class ProposalAmendedPayloadTest extends TestCase
{
    private function makeUser(string $email = 'test@example.com'): TestUser
    {
        return TestUser::create(['name' => 'Test User', 'email' => $email]);
    }

    /**
     * With amendedPayload: original stored, new payload set, metadata persisted.
     */
    public function test_accept_with_amended_payload_stores_original(): void
    {
        $user = $this->makeUser();
        $originalPayload = ['quantity' => 5, 'item' => 'widget'];
        $amended = ['quantity' => 3, 'item' => 'gadget', 'gift' => true];

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => $originalPayload,
        ]);

        $result = $proposal->accept($user->id, amendedPayload: $amended, note: 'Modified quantity');
        $this->assertTrue($result);

        $proposal->refresh();
        $this->assertSame('accepted', $proposal->status);
        $this->assertSame($amended, $proposal->payload);
        $this->assertSame($originalPayload, $proposal->original_payload);
        $this->assertSame('Modified quantity', $proposal->decision_note);
        $this->assertEquals((string) $user->id, $proposal->decided_by);
        $this->assertNotNull($proposal->decided_at);
    }

    /**
     * Without amendedPayload: original_payload still gets the current payload.
     */
    public function test_accept_without_amended_payload_stores_original(): void
    {
        $user = $this->makeUser();
        $payload = ['key' => 'value'];

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => $payload,
        ]);

        $result = $proposal->accept($user->id);
        $this->assertTrue($result);

        $proposal->refresh();
        $this->assertSame($payload, $proposal->original_payload);
    }

    /**
     * Direct accept() with invalid amendedPayload does NOT throw — accept()
     * does not validate against handler rules. Only decide() does.
     * So this test verifies that accept() works without handler validation.
     */
    public function test_accept_without_handler_works(): void
    {
        $user = $this->makeUser();

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'no_handler_type',
            'payload' => ['category' => 'a'],
        ]);

        $result = $proposal->accept($user->id, amendedPayload: ['category' => 'short'], note: 'Too short');
        $this->assertTrue($result);
        $this->assertSame('accepted', $proposal->refresh()->status);
        $this->assertSame(['category' => 'short'], $proposal->payload);
    }

    /**
     * Via ProposalDecisions::decide with handler: valid payload accepts,
     * invalid payload throws ValidationException.
     *
     * Note: validatePayloadForWrite wraps the payload in ['payload' => $payload]
     * before validation, so handler rules must use dot-notation to access keys
     * inside the payload (e.g. 'payload.priority').
     */
    public function test_decide_with_valid_payload_accepts(): void
    {
        $user = $this->makeUser();

        // Rules with dot-notation: rules() returns the handler's rules,
        // but validatePayloadForWrite expects them to match the input
        // structure ['payload' => $payload]. In real usage, the host must
        // define rules that reference payload keys correctly.
        // For this test, we use rules that don't require specific keys.
        $handler = new FakeProposalHandler(
            type: 'validated_type',
            rules: ['payload.name' => 'required|string|min:2'],
            result: ['executed' => true],
        );

        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);
        Config::set('ai-agents.proposals.execute', 'none');

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'validated_type',
            'payload' => ['name' => 'test'],
        ]);

        $amended = ['name' => 'modified', 'note' => 'downgraded'];
        $result = ProposalDecisions::instance()->decide(
            $proposal,
            (string) $user->id,
            ProposalDecisions::DECISION_ACCEPT,
            payload: $amended,
            note: 'Lower priority',
        );
        $this->assertTrue($result);

        $this->assertSame('accepted', $proposal->refresh()->status);
        $this->assertSame($amended, $proposal->payload);
        $this->assertSame(['name' => 'test'], $proposal->original_payload);
    }

    /**
     * Via ProposalDecisions::decide with invalid payload: throws ValidationException,
     * proposal stays pending.
     *
     * The amended payload doesn't have a 'name' key, so 'payload.name' rule fails.
     */
    public function test_decide_with_invalid_payload_throws(): void
    {
        $user = $this->makeUser();

        $handler = new FakeProposalHandler(
            type: 'priority_type',
            rules: ['payload.name' => 'required|string|min:3'],
            result: ['ok' => true],
        );

        $registry = app(ProposalHandlerRegistry::class);
        $registry->register($handler);
        Config::set('ai-agents.proposals.execute', 'none');

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'priority_type',
            'payload' => ['name' => 'original'],
        ]);

        // Amended payload missing 'name' key → rule fails.
        $this->expectException(ValidationException::class);

        try {
            ProposalDecisions::instance()->decide(
                $proposal,
                (string) $user->id,
                ProposalDecisions::DECISION_ACCEPT,
                payload: ['other' => 'value'], // no 'name' key
            );
        } finally {
            $this->assertSame('pending', $proposal->refresh()->status);
            $this->assertSame(['name' => 'original'], $proposal->payload);
            $this->assertNull($proposal->original_payload);
        }
    }
}
