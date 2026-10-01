<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Proposals;

use HomeSide\AiAgents\Events\ActionProposalAccepted;
use HomeSide\AiAgents\Events\ActionProposalRejected;
use HomeSide\AiAgents\Models\AiActionProposal;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use Illuminate\Support\Facades\Event;

/**
 * Backward compatibility: accept()/reject() without arguments still work.
 */
final class ProposalBackwardCompatibilityTest extends TestCase
{
    private function makeUser(string $email = 'test@example.com'): TestUser
    {
        return TestUser::create(['name' => 'Test User', 'email' => $email]);
    }

    /**
     * accept() without arguments works (deprecated) and leaves decided_by = null.
     */
    public function test_accept_without_arguments_works(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $result = $proposal->accept();
        $this->assertTrue($result);
        $this->assertSame('accepted', $proposal->refresh()->status);
        $this->assertNull($proposal->decided_by);
        $this->assertNotNull($proposal->decided_at);
    }

    /**
     * reject() without arguments works (deprecated) and leaves decided_by = null.
     */
    public function test_reject_without_arguments_works(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $result = $proposal->reject();
        $this->assertTrue($result);
        $this->assertSame('rejected', $proposal->refresh()->status);
        $this->assertNull($proposal->decided_by);
        $this->assertNotNull($proposal->decided_at);
    }

    /**
     * The existing test from AiActionProposalValidatedWriteTest::test_lifecycle_transitions
     * still works — verify the core pattern.
     */
    public function test_lifecycle_transitions_bc(): void
    {
        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'create_recipe',
            'payload' => ['recipe' => ['name' => 'Lasaña']],
        ]);

        $this->assertTrue($proposal->isPending());

        $proposal->reject();
        $this->assertFalse($proposal->refresh()->isPending());
        $this->assertSame('rejected', $proposal->status);

        $proposal->updateValidated(['status' => 'pending']);
        $proposal->accept();
        $this->assertSame('accepted', $proposal->refresh()->status);
    }

    /**
     * accept() without arguments fires event with null deciderId.
     */
    public function test_accept_without_arguments_fires_event_with_null(): void
    {
        Event::fake();

        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $proposal->accept();

        Event::assertDispatched(ActionProposalAccepted::class, function (ActionProposalAccepted $e): bool {
            $this->assertNull($e->deciderId);

            return true;
        });
    }

    /**
     * reject() without arguments fires event with null deciderId.
     */
    public function test_reject_without_arguments_fires_event_with_null(): void
    {
        Event::fake();

        $user = $this->makeUser();
        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'test_action',
            'payload' => ['key' => 'value'],
        ]);

        $proposal->reject();

        Event::assertDispatched(ActionProposalRejected::class, function (ActionProposalRejected $e): bool {
            $this->assertNull($e->deciderId);

            return true;
        });
    }
}
