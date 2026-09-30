<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Models;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\Models\AiActionProposal;
use HomeSide\AiAgents\Models\AiConversation;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use HomeSide\AiAgents\Tests\Unit\Fixtures\DummyAgent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Validated writes, lifecycle and tenant scoping of AiActionProposal —
 * the human-in-the-loop model.
 */
final class AiActionProposalValidatedWriteTest extends TestCase
{
    private function makeUser(string $email = 'ana@example.com'): TestUser
    {
        return TestUser::create(['name' => 'Ana', 'email' => $email]);
    }

    private function makeConversation(TestUser $user): AiConversation
    {
        $this->app->make(AgentRegistry::class)->register('recipes.generator', DummyAgent::class);

        return AiConversation::createValidated([
            'user_id' => $user->id,
            'agent' => 'recipes.generator',
        ]);
    }

    /**
     * A valid proposal is created with the pending status by default.
     */
    public function test_valid_proposal_is_created_pending(): void
    {
        $user = $this->makeUser();
        $conversation = $this->makeConversation($user);

        $proposal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'type' => 'add_shopping_items',
            'payload' => ['list_id' => 'abc'],
            'reason' => 'You asked for it.',
        ]);

        $this->assertDatabaseHas('ai_action_proposals', [
            'id' => $proposal->id,
            'user_id' => $user->id,
            'type' => 'add_shopping_items',
            'status' => 'pending',
        ]);
        $this->assertTrue($proposal->isPending());
        $this->assertFalse($proposal->isExpired());
        $this->assertSame($conversation->id, $proposal->conversation->id);
    }

    /**
     * Types are host-defined slugs: anything outside the format is rejected.
     */
    public function test_invalid_type_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->expectException(ValidationException::class);

        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'Add Shopping!',
            'payload' => ['list_id' => 'abc'],
        ]);
    }

    /**
     * The status stays within the fixed lifecycle set.
     */
    public function test_invalid_status_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('status');

        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'add_shopping_items',
            'payload' => ['list_id' => 'abc'],
            'status' => 'weird',
        ]);
    }

    /**
     * A proposal cannot attach to a conversation that does not exist.
     */
    public function test_missing_conversation_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not exist');

        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'conversation_id' => '0e2c7a44-1111-4c2e-8f3e-000000000000',
            'type' => 'add_shopping_items',
            'payload' => ['list_id' => 'abc'],
        ]);
    }

    /**
     * A proposal can never attach to another user's conversation.
     */
    public function test_foreign_conversation_is_rejected(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $other = $this->makeUser('other@example.com');
        $conversation = $this->makeConversation($owner);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('different user');

        AiActionProposal::createValidated([
            'user_id' => $other->id,
            'conversation_id' => $conversation->id,
            'type' => 'add_shopping_items',
            'payload' => ['list_id' => 'abc'],
        ]);
    }

    /**
     * The lifecycle transitions update the row and the helpers reflect it.
     */
    public function test_lifecycle_transitions(): void
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
     * expireStale flips only pending proposals whose expiry passed —
     * resolved proposals and future expiries are untouched.
     */
    public function test_expire_stale_marks_only_expired_pending_proposals(): void
    {
        $user = $this->makeUser();

        $stale = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'add_shopping_items',
            'payload' => ['list_id' => 'a'],
            'expires_at' => now()->subHour(),
        ]);
        $upcoming = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'add_shopping_items',
            'payload' => ['list_id' => 'b'],
            'expires_at' => now()->addHour(),
        ]);
        $accepted = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'add_shopping_items',
            'payload' => ['list_id' => 'c'],
            'expires_at' => now()->subHour(),
            'status' => 'accepted',
        ]);
        $undated = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'add_shopping_items',
            'payload' => ['list_id' => 'd'],
        ]);

        $this->assertSame(1, AiActionProposal::expireStale());

        $this->assertSame('expired', $stale->refresh()->status);
        $this->assertSame('pending', $upcoming->refresh()->status);
        $this->assertSame('accepted', $accepted->refresh()->status);
        $this->assertSame('pending', $undated->refresh()->status);
    }

    /**
     * With tenancy enabled the virtual scope resolves the tenant column and
     * forTenant() scopes queries accordingly.
     */
    public function test_tenant_scoped_proposal_when_tenancy_enabled(): void
    {
        config()->set('ai-agents.tenant.enabled', true);
        config()->set('ai-agents.tenant.foreign_key', 'household_id');

        // Simulate the package's tenant migration for this table (the suite
        // boots with tenancy disabled, so the column is not loaded).
        Schema::table('ai_action_proposals', function (Blueprint $table): void {
            $table->string('household_id')->nullable()->index();
        });

        $user = $this->makeUser();
        $personal = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'add_shopping_items',
            'payload' => ['list_id' => 'a'],
            'scope' => ['user' => $user->id],
        ]);
        $scoped = AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'add_shopping_items',
            'payload' => ['list_id' => 'b'],
            'scope' => ['tenant' => 'h-1'],
        ]);

        $this->assertSame('h-1', $scoped->household_id);
        $this->assertNull($personal->refresh()->household_id);
        $this->assertTrue(AiActionProposal::forTenant('h-1')->whereKey($scoped->id)->exists());
        $this->assertFalse(AiActionProposal::forTenant('h-1')->whereKey($personal->id)->exists());

        Schema::table('ai_action_proposals', function (Blueprint $table): void {
            $table->dropIndex(['household_id']);
            $table->dropColumn('household_id');
        });
    }

    /**
     * A request for a tenant scope while tenancy is disabled is rejected
     * instead of silently dropping the ownership.
     */
    public function test_tenant_scope_rejected_when_tenancy_disabled(): void
    {
        config()->set('ai-agents.tenant.enabled', false);
        $user = $this->makeUser();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Tenant support is disabled');

        AiActionProposal::createValidated([
            'user_id' => $user->id,
            'type' => 'add_shopping_items',
            'payload' => ['list_id' => 'a'],
            'scope' => ['tenant' => 'h-1'],
        ]);
    }

    /**
     * Plain create() stays untouched for hosts that manage validation —
     * documented behaviour, not an encouragement.
     */
    public function test_plain_create_remains_available(): void
    {
        $user = $this->makeUser();

        $proposal = AiActionProposal::create([
            'user_id' => $user->id,
            'type' => 'anything_goes',
            'payload' => [],
        ]);

        $this->assertTrue(DB::table('ai_action_proposals')->where('id', $proposal->id)->exists());
    }
}
