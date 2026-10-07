<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Conversations;

use HomeSide\AiAgents\Conversations\ConversationAccessGuard;
use HomeSide\AiAgents\Exceptions\ConversationNotFoundException;
use HomeSide\AiAgents\Execution\AiExecutionContextData;
use HomeSide\AiAgents\Models\AiConversation;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;

/**
 * The conversation access guard: owner + agent + tenant must all match in
 * the SAME query, and a missing conversation is indistinguishable from an
 * inaccessible one.
 */
final class ConversationAccessGuardTest extends TestCase
{
    private function guard(): ConversationAccessGuard
    {
        return $this->app->make(ConversationAccessGuard::class);
    }

    private function conversation(int|string $userId, string $agent, array $overrides = []): AiConversation
    {
        return AiConversation::create([
            'user_id' => $userId,
            'agent' => $agent,
            'title' => 'Chat',
            ...$overrides,
        ]);
    }

    public function test_owner_can_access_own_conversation(): void
    {
        $user = TestUser::create(['name' => 'A', 'email' => 'a@example.com']);
        $conversation = $this->conversation($user->id, 'recipes.generator');

        $resolved = $this->guard()->authorize(
            $conversation->id,
            'recipes.generator',
            new AiExecutionContextData(userId: $user->id),
        );

        $this->assertSame($conversation->id, $resolved->id);
    }

    public function test_another_user_cannot_access_the_conversation(): void
    {
        $owner = TestUser::create(['name' => 'Owner', 'email' => 'owner@example.com']);
        $other = TestUser::create(['name' => 'Other', 'email' => 'other@example.com']);
        $conversation = $this->conversation($owner->id, 'recipes.generator');

        $this->expectException(ConversationNotFoundException::class);
        $this->expectExceptionMessage($conversation->id);

        $this->guard()->authorize(
            $conversation->id,
            'recipes.generator',
            new AiExecutionContextData(userId: $other->id),
        );
    }

    public function test_another_agent_cannot_access_the_conversation(): void
    {
        $user = TestUser::create(['name' => 'A', 'email' => 'a2@example.com']);
        $conversation = $this->conversation($user->id, 'recipes.generator');

        $this->expectException(ConversationNotFoundException::class);

        $this->guard()->authorize(
            $conversation->id,
            'recipes.suggester',
            new AiExecutionContextData(userId: $user->id),
        );
    }

    public function test_missing_conversation_is_indistinguishable_from_forbidden(): void
    {
        $user = TestUser::create(['name' => 'A', 'email' => 'a3@example.com']);
        $conversation = $this->conversation($user->id, 'recipes.generator');
        $other = TestUser::create(['name' => 'B', 'email' => 'b3@example.com']);

        $missingMessage = null;
        try {
            $this->guard()->authorize('00000000-0000-7000-8000-000000000000', 'recipes.generator', new AiExecutionContextData(userId: $user->id));
        } catch (ConversationNotFoundException $e) {
            $missingMessage = $e->getMessage();
        }

        $forbiddenMessage = null;
        try {
            $this->guard()->authorize($conversation->id, 'recipes.generator', new AiExecutionContextData(userId: $other->id));
        } catch (ConversationNotFoundException $e) {
            $forbiddenMessage = $e->getMessage();
        }

        $this->assertNotNull($missingMessage);
        $this->assertNotNull($forbiddenMessage);
        // Same shape; the only difference is the id echoed back, never the reason.
        $this->assertStringContainsString('could not be found', $missingMessage);
        $this->assertStringContainsString('could not be found', $forbiddenMessage);
    }

    public function test_none_tenancy_ignores_tenant(): void
    {
        $user = TestUser::create(['name' => 'A', 'email' => 'a4@example.com']);
        $conversation = $this->conversation($user->id, 'recipes.generator');

        $resolved = $this->guard()->authorize(
            $conversation->id,
            'recipes.generator',
            new AiExecutionContextData(userId: $user->id, tenantId: 'whatever'),
        );

        $this->assertSame($conversation->id, $resolved->id);
    }
}
