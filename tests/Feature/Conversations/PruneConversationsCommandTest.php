<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Conversations;

use HomeSide\AiAgents\Models\AiConversation;
use HomeSide\AiAgents\Models\AiConversationMessage;
use HomeSide\AiAgents\Models\AiRun;
use HomeSide\AiAgents\Tests\TestCase;
use HomeSide\AiAgents\Tests\TestUser;
use Illuminate\Support\Carbon;

/**
 * Conversation retention pruning: independent from AiRun telemetry.
 */
final class PruneConversationsCommandTest extends TestCase
{
    private TestUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = TestUser::create(['name' => 'Prune', 'email' => 'prune@example.com']);
    }

    private function makeConversation(int $ageDays): AiConversation
    {
        $conversation = AiConversation::create([
            'user_id' => $this->user->id,
            'agent' => 'recipes.generator',
            'title' => 'Chat',
        ]);

        $conversation->forceFill([
            'created_at' => Carbon::now()->subDays($ageDays),
            'updated_at' => Carbon::now()->subDays($ageDays),
        ])->save();

        return $conversation;
    }

    public function test_old_conversations_are_pruned_and_recent_ones_kept(): void
    {
        config(['ai-agents.conversations.retention.days' => 30]);

        $old = $this->makeConversation(31);
        $recent = $this->makeConversation(29);

        $this->artisan('ai-agents:conversations:prune')->assertSuccessful();

        $this->assertNull(AiConversation::query()->find($old->id));
        $this->assertNotNull(AiConversation::query()->find($recent->id));
    }

    public function test_messages_cascade_with_their_conversation(): void
    {
        config(['ai-agents.conversations.retention.days' => 30]);

        $old = $this->makeConversation(31);

        AiConversationMessage::create([
            'conversation_id' => $old->id,
            'user_id' => $this->user->id,
            'agent' => 'recipes.generator',
            'role' => AiConversationMessage::ROLE_USER,
            'payload' => ['content' => 'hola', 'attachments' => [], 'steps' => [], 'meta' => []],
            'status' => AiConversationMessage::STATUS_COMPLETED,
        ]);

        $this->artisan('ai-agents:conversations:prune')->assertSuccessful();

        $this->assertSame(0, AiConversationMessage::query()->where('conversation_id', $old->id)->count());
    }

    public function test_null_retention_is_a_no_op(): void
    {
        config(['ai-agents.conversations.retention.days' => null]);

        $old = $this->makeConversation(365);

        $this->artisan('ai-agents:conversations:prune')->assertSuccessful();

        $this->assertNotNull(AiConversation::query()->find($old->id));
    }

    public function test_runs_are_untouched_by_pruning(): void
    {
        config(['ai-agents.conversations.retention.days' => 30]);

        $this->makeConversation(31);

        $run = AiRun::create([
            'user_id' => $this->user->id,
            'agent' => 'recipes.generator',
            'status' => 'ok',
            'duration_ms' => 10,
            'content_mode' => 'encrypted',
        ]);

        $this->artisan('ai-agents:conversations:prune')->assertSuccessful();

        $this->assertNotNull(AiRun::query()->find($run->id));
    }
}
